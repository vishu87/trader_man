<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use App\Models\User;
use App\Models\Trade;
use App\Models\TradeAction;
use App\Services\KiteService;
use App\Services\TradeLedger;

/**
 * Real trades: you add a stock, then record every buy and sell you actually made. Quantity held, average cost and profit / loss
 * (realised on what was sold, unrealised on what is left at the live price) come from App\Services\TradeLedger.
 * A trade is 'active' or 'archived'. Nothing here places an order.
 */
class TradesController extends Controller
{
    public function index()
    {
        return Inertia::render('Trades/Index')->withViewData(['sidebar' => 'trades']);
    }

    private function fail(string $message)
    {
        return Response::json(['success' => false, 'message' => $message], 200);
    }

    private function ok(string $message, array $extra = [])
    {
        return Response::json(['success' => true, 'message' => $message] + $extra, 200);
    }

    private function actionsOf(int $tradeId): array
    {
        return TradeAction::where('trade_id', $tradeId)->get()->map(fn ($a) => self::actionArray($a))->all();
    }

    private static function actionArray(TradeAction $a): array
    {
        return [
            'id' => $a->id, 'side' => $a->side, 'qty' => (int) $a->qty, 'price' => (float) $a->price, 'charges' => (float) $a->charges,
            'action_date' => substr((string) $a->action_date, 0, 10), 'notes' => $a->notes,
        ];
    }

    // ─── API: everything the page needs ──────────────────────────────────────

    public function list(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $trades  = Trade::orderBy('symbol')->orderByDesc('id')->get();
        $actions = TradeAction::whereIn('trade_id', $trades->pluck('id'))->get()->groupBy('trade_id');

        // live prices only for what is still held in an active trade; the last stored close stands in when Kite has none
        $held = [];
        $ledgers = [];
        foreach ($trades as $t) {
            $ledgers[$t->id] = TradeLedger::compute(($actions[$t->id] ?? collect())->map(fn ($a) => self::actionArray($a))->all());
            if ($ledgers[$t->id]['qty'] > 0) $held[$t->exchange . ':' . $t->symbol] = true;
        }
        $kite = KiteService::getLtps(array_keys($held), $request->boolean('refresh'));

        $rows = $trades->map(function ($t) use ($ledgers, $kite, $held) {
            $led = $ledgers[$t->id];
            $key = $t->exchange . ':' . $t->symbol;
            $ltp = isset($held[$key]) ? ($kite['ltps'][$key] ?? KiteService::NO_LTP) : KiteService::NO_LTP;

            $price = null; $source = null;
            if ($led['qty'] > 0) {
                if ($ltp > 0)                                   { $price = (float) $ltp; $source = 'ltp'; }
                elseif (($close = self::lastClose($t)) !== null) { $price = $close;       $source = 'close'; }
            }
            $mark = TradeLedger::mark($led, $price);

            return [
                'id' => $t->id, 'exchange' => $t->exchange, 'symbol' => $t->symbol, 'status' => $t->status, 'notes' => $t->notes,
                'archived_at' => $t->archived_at ? $t->archived_at->toDateString() : null,
                'qty' => $led['qty'], 'avg_price' => $led['avg_price'], 'invested' => $led['invested'],
                'bought_qty' => $led['bought_qty'], 'bought_value' => $led['bought_value'], 'sold_qty' => $led['sold_qty'], 'sold_value' => $led['sold_value'],
                'realised' => $led['realised'], 'realised_gross' => $led['realised_gross'], 'charges' => $led['charges'],
                'ltp' => $ltp, 'price' => $mark['price'], 'price_source' => $source, 'value' => $mark['value'],
                'unrealised' => $mark['unrealised'], 'unrealised_pct' => $mark['unrealised_pct'], 'total' => $mark['total'], 'return_pct' => $mark['return_pct'],
                'first_date' => $led['rows'] ? $led['rows'][0]['action_date'] : null,
                'last_date' => $led['rows'] ? end($led['rows'])['action_date'] : null,
                'actions' => $led['rows'],
            ];
        })->values();

        return Response::json([
            'success'   => true,
            'trades'    => $rows,
            'summary'   => self::summary($rows->where('status', 'active')),
            'archived_summary' => self::summary($rows->where('status', 'archived')),
            'ltp_error' => $kite['error'] ?? null,
        ], 200);
    }

    /** Totals over a set of trade rows. Unrealised / value skip trades that have no price at all and say how many. */
    public static function summary($rows): array
    {
        $priced   = $rows->filter(fn ($r) => $r['qty'] === 0 || $r['price'] !== null);
        $unpriced = $rows->reject(fn ($r) => $r['qty'] === 0 || $r['price'] !== null);
        return [
            'trades'     => $rows->count(),
            'holding'    => $rows->where('qty', '>', 0)->count(),
            'invested'   => round($rows->sum('invested'), 2),
            'value'      => round($priced->sum(fn ($r) => $r['value'] ?? 0), 2),
            'unrealised' => round($priced->sum(fn ($r) => $r['unrealised'] ?? 0), 2),
            'realised'   => round($rows->sum('realised'), 2),
            'total'      => round($priced->sum(fn ($r) => $r['total'] ?? 0) + $unpriced->sum('realised'), 2),
            'no_price'   => $unpriced->count(),
        ];
    }

    private static function lastClose(Trade $t): ?float
    {
        $c = DB::table('stock_candles')->where('exchange', $t->exchange)->where('symbol', $t->symbol)->where('timeframe', 'daily')->orderByDesc('date')->value('close');
        return $c !== null ? (float) $c : null;
    }

    // ─── API: trades ─────────────────────────────────────────────────────────

    /** Add a trade for a stock, optionally with its first buy. With `id` it edits the notes. */
    public function save(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'id'        => 'nullable|integer',
            'symbol'    => 'required_without:id|nullable|string|max:50',
            'exchange'  => 'nullable|in:NSE,BSE',
            'notes'     => 'nullable|string|max:500',
            'qty'       => 'nullable|integer|min:1',
            'price'     => 'nullable|numeric|gt:0',
            'charges'   => 'nullable|numeric|min:0',
            'action_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ], ['action_date.before_or_equal' => 'The date cannot be in the future']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        // notes only
        if ($request->filled('id')) {
            $trade = Trade::find($request->id);
            if (!$trade) return $this->fail('That trade no longer exists');
            $trade->update(['notes' => trim((string) $request->notes) ?: null, 'updated_at' => now()]);
            return $this->ok('Saved');
        }

        $exchange = $request->exchange ?: 'NSE';
        $symbol   = strtoupper(trim($request->symbol));
        if (!preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) return $this->fail('That does not look like a trading symbol');

        if (Trade::where('exchange', $exchange)->where('symbol', $symbol)->where('status', 'active')->exists()) {
            return $this->fail("{$exchange}:{$symbol} already has an active trade — add its buys and sells there, or archive it first");
        }

        $hasFirst = $request->filled('qty') || $request->filled('price');
        if ($hasFirst && !($request->filled('qty') && $request->filled('price'))) {
            return $this->fail('For a first buy, enter both the quantity and the price (or leave both empty)');
        }

        $trade = DB::transaction(function () use ($request, $exchange, $symbol, $hasFirst) {
            $trade = Trade::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'status' => 'active',
                'notes' => trim((string) $request->notes) ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($hasFirst) {
                TradeAction::create([
                    'trade_id' => $trade->id, 'side' => 'buy', 'qty' => (int) $request->qty, 'price' => (float) $request->price,
                    'charges' => (float) ($request->charges ?: 0), 'action_date' => $request->action_date ?: today()->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $trade;
        });

        return $this->ok("{$symbol} added", ['id' => $trade->id]);
    }

    /** Archive (done with it) or bring it back to active. */
    public function status(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer', 'status' => 'required|in:active,archived']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $trade = Trade::find($request->id);
        if (!$trade) return $this->fail('That trade no longer exists');

        if ($request->status === 'active' && Trade::where('exchange', $trade->exchange)->where('symbol', $trade->symbol)->where('status', 'active')->where('id', '!=', $trade->id)->exists()) {
            return $this->fail("{$trade->exchange}:{$trade->symbol} already has another active trade — archive that one first");
        }

        $trade->update(['status' => $request->status, 'archived_at' => $request->status === 'archived' ? now() : null, 'updated_at' => now()]);

        return $this->ok($request->status === 'archived' ? "{$trade->symbol} archived" : "{$trade->symbol} is active again");
    }

    public function delete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $trade = Trade::find($request->id);
        if (!$trade) return $this->fail('That trade no longer exists');

        DB::transaction(function () use ($trade) {
            TradeAction::where('trade_id', $trade->id)->delete();
            $trade->delete();
        });

        return $this->ok("{$trade->symbol} deleted");
    }

    // ─── API: the buys and sells of a trade ──────────────────────────────────

    /** Record a buy or a sell, or (with `id`) change one. Refused if the sequence would ever sell more than was held. */
    public function actionSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'id'          => 'nullable|integer',
            'trade_id'    => 'required|integer',
            'side'        => 'required|in:buy,sell',
            'qty'         => 'required|integer|min:1',
            'price'       => 'required|numeric|gt:0',
            'charges'     => 'nullable|numeric|min:0',
            'action_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'notes'       => 'nullable|string|max:255',
        ], [
            'qty.integer' => 'Quantity must be a whole number of shares', 'action_date.before_or_equal' => 'The date cannot be in the future',
            'action_date.required' => 'Pick the date', 'price.gt' => 'The price must be above zero', 'qty.min' => 'Quantity must be at least 1',
        ]);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $trade = Trade::find($request->trade_id);
        if (!$trade) return $this->fail('That trade no longer exists');

        $new = [
            'id' => $request->filled('id') ? (int) $request->id : null, 'side' => $request->side, 'qty' => (int) $request->qty, 'price' => (float) $request->price,
            'charges' => (float) ($request->charges ?: 0), 'action_date' => $request->action_date,
        ];

        // the whole sequence, as it would be after this change, must never go below zero shares
        $others = array_values(array_filter($this->actionsOf($trade->id), fn ($a) => $a['id'] !== $new['id']));
        if ($problem = TradeLedger::problem(array_merge($others, [$new]))) return $this->fail($problem);

        $values = ['side' => $new['side'], 'qty' => $new['qty'], 'price' => $new['price'], 'charges' => $new['charges'],
                   'action_date' => $new['action_date'], 'notes' => trim((string) $request->notes) ?: null, 'updated_at' => now()];

        if ($new['id'] !== null) {
            $action = TradeAction::where('id', $new['id'])->where('trade_id', $trade->id)->first();
            if (!$action) return $this->fail('That entry no longer exists');
            $action->update($values);
        } else {
            TradeAction::create(['trade_id' => $trade->id, 'created_at' => now()] + $values);
        }
        $trade->update(['updated_at' => now()]);

        return $this->ok(ucfirst($new['side']) . ' ' . ($new['id'] !== null ? 'updated' : 'recorded'));
    }

    public function actionDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $action = TradeAction::find($request->id);
        if (!$action) return $this->fail('That entry no longer exists');

        // removing a buy that later sells depend on would leave those sells with nothing to sell
        $rest = array_values(array_filter($this->actionsOf($action->trade_id), fn ($a) => $a['id'] !== $action->id));
        if ($problem = TradeLedger::problem($rest)) return $this->fail("Can't delete it: " . lcfirst($problem));

        $action->delete();

        return $this->ok('Deleted');
    }

    /**
     * Refresh the candles of the ACTIVE trades' symbols only (forced, and only the days after the last stored one — today's candle
     * while the market is open). For checking the day's candles between the full runs.
     */
    public function sync(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $symbols = Trade::where('status', 'active')->pluck('symbol')->unique()->values()->all();
        if (!$symbols) return $this->fail('There are no active trades to sync');

        $tok = \App\Services\KiteService::syncToken();
        if (!$tok['ok']) return $this->fail($tok['message']);

        $result = \App\Services\CandleSyncService::startInBackground(implode(',', $symbols), true, true);
        return Response::json(['success' => $result['started'], 'message' => $result['started'] ? 'Syncing ' . count($symbols) . ' symbol' . (count($symbols) === 1 ? '' : 's') : $result['message'], 'symbols' => count($symbols)], 200);
    }
}
