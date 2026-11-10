<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Models\PortfolioBucket;
use App\Models\PortfolioHolding;
use App\Models\AppSetting;
use App\Models\ChartView;
use App\Services\KiteService;
use App\Services\NseIpoCsvImporter;

class WatchlistController extends Controller
{
    const MAX_BULK_LINES = 500;

    // ─── Page ────────────────────────────────────────────────────────────────

    public function index()
    {
        return Inertia::render('Watchlist/Index')->withViewData(['sidebar' => 'watchlist']);
    }

    // ─── API: everything the page needs in one call ──────────────────────────

    public function data(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $lists = Watchlist::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $items = WatchlistItem::orderBy('symbol')->get();

        $kite = KiteService::getLtps($items->map(fn ($i) => $i->exchange . ':' . $i->symbol)->all(), (bool) $request->refresh);

        $list_names = $lists->pluck('name', 'id');
        $missing = 0;
        $viewed = ChartView::lastViewedMap();   // 'NSE:INFY' => date its chart was last viewed

        // what is actually OWNED (tracking-only stocks and empty placeholders do not count), keyed 'NSE:INFY'
        $bucket_names = PortfolioBucket::pluck('name', 'id');
        $owned = [];
        foreach (PortfolioHolding::where('is_tracking', 0)->where('quantity', '>', 0)->get() as $h) {
            $k = $h->exchange . ':' . $h->zerodha_symbol;
            $owned[$k]['quantity'] = ($owned[$k]['quantity'] ?? 0) + (int) $h->quantity;
            $owned[$k]['buckets'][] = ($bucket_names[$h->bucket_id] ?? '—') . ' (' . (int) $h->quantity . ')';
        }

        $rows = $items->map(function ($i) use ($kite, $list_names, $owned, $viewed, &$missing) {
            $key   = $i->exchange . ':' . $i->symbol;
            $ltp   = $kite['ltps'][$key] ?? KiteService::NO_LTP;
            $has   = $ltp > 0;
            $buy   = $i->buy_price !== null ? (float) $i->buy_price : null;
            $sl    = $i->stop_loss !== null ? (float) $i->stop_loss : null;

            // Remember Kite's instrument token (the candle sync + chart links need it); raw update leaves updated_at alone
            $token = $kite['tokens'][$key] ?? null;
            if ($token && (int) $i->instrument_token !== $token) {
                DB::table('watchlist_items')->where('id', $i->id)->update(['instrument_token' => $token]);
                $i->instrument_token = $token;
            }

            if (!$has) $missing++;

            return [
                'id'               => $i->id,
                'watchlist_id'     => $i->watchlist_id,
                'watchlist_name'   => $list_names[$i->watchlist_id] ?? '—',
                'symbol'           => $i->symbol,
                'name'             => $i->name,                                     // set by the IPO job
                'listing_date'     => $i->listing_date?->toDateString(),            // Y-m-d, or null
                'exchange'         => $i->exchange,
                'instrument_token' => $i->instrument_token ? (int) $i->instrument_token : null,
                'ltp'              => $has ? $ltp : KiteService::NO_LTP,
                'buy_price'        => $buy,
                'stop_loss'        => $sl,
                // vs-buy %, distance to stop loss, status and position size are derived in the browser
                // (resources/js/Service/Utils/Watchlist.js) so they update the moment a price is edited inline
                'follow'           => (int) $i->follow,
                'last_viewed'      => $viewed[$key] ?? null,
                // null, or ['quantity' => total held, 'buckets' => ['Core (250)', ...]]
                'in_portfolio'     => $owned[$key] ?? null,
            ];
        })->values();

        return Response::json([
            'success'     => true,
            'watchlists'  => $lists,
            'items'       => $rows,
            'settings'    => ['max_loss_per_trade' => AppSetting::maxLossPerTrade()],
            'ltp_error'   => $kite['error'],
            'ltp_missing' => $missing,
            'today'       => today()->toDateString(),
        ], 200);
    }

    // ─── API: Watchlists ─────────────────────────────────────────────────────

    public function listSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100', Rule::unique('watchlists', 'name')->ignore($request->id)],
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $list = $request->id ? Watchlist::find($request->id) : new Watchlist;
        if (!$list) {
            return Response::json(['success' => false, 'message' => 'Watchlist not found'], 200);
        }

        $list->name = trim($request->name);
        $list->save();

        return Response::json(['success' => true, 'message' => $request->id ? 'Watchlist updated' : 'Watchlist added', 'id' => $list->id], 200);
    }

    public function listDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $list = Watchlist::find($request->id);
        if (!$list) {
            return Response::json(['success' => false, 'message' => 'Watchlist not found'], 200);
        }

        $count = WatchlistItem::where('watchlist_id', $list->id)->count();
        if ($count > 0) {
            return Response::json(['success' => false, 'message' => "Watchlist still has {$count} symbol(s). Remove them first."], 200);
        }

        $list->delete();
        return Response::json(['success' => true, 'message' => 'Watchlist deleted'], 200);
    }

    // ─── API: Items ──────────────────────────────────────────────────────────

    public function itemSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $symbol = $this->cleanSymbol($request->symbol);

        $validator = Validator::make(array_merge($request->all(), ['symbol' => $symbol]), [
            'watchlist_id' => 'required|integer|exists:watchlists,id',
            'symbol'       => ['required', 'string', 'max:50', 'regex:' . $this->symbolPattern(),
                               Rule::unique('watchlist_items', 'symbol')
                                   ->where('watchlist_id', $request->watchlist_id)
                                   ->where('exchange', $request->exchange ?: 'NSE')
                                   ->ignore($request->id)],
            'exchange'     => 'nullable|in:NSE,BSE',
            'buy_price'    => 'nullable|numeric|min:0',
            'stop_loss'    => 'nullable|numeric|min:0',
            'follow'       => 'nullable|in:0,1',
        ], [
            'symbol.unique' => 'This symbol is already in that watchlist',
            'symbol.regex'  => 'Symbol can only contain letters, numbers and & - . _ (and spaces)',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $buy = $this->nullableNumber($request->buy_price);
        $sl  = $this->nullableNumber($request->stop_loss);

        if ($buy !== null && $sl !== null && $sl >= $buy) {
            return Response::json(['success' => false, 'message' => 'Stop loss must be below the buy price'], 200);
        }

        $item = $request->id ? WatchlistItem::find($request->id) : new WatchlistItem;
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Symbol not found'], 200);
        }

        $exchange = $request->exchange ?: 'NSE';

        // a different instrument now → forget the old token; it is re-captured with the next LTP fetch
        if ($item->exists && ($item->symbol !== $symbol || $item->exchange !== $exchange)) {
            $item->instrument_token = null;
        }

        $item->watchlist_id = $request->watchlist_id;
        $item->symbol       = $symbol;
        $item->exchange     = $exchange;
        $item->buy_price    = $buy;
        $item->stop_loss    = $sl;
        if ($request->has('follow')) {
            $item->follow = $request->follow ? 1 : 0;
        }
        $item->save();

        return Response::json(['success' => true, 'message' => $request->id ? 'Symbol updated' : 'Symbol added'], 200);
    }

    public function itemFollow(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
            'follow' => 'required|in:0,1',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $query = WatchlistItem::whereIn('id', $request->ids);
        $count = $query->count();
        $query->update(['follow' => (int) $request->follow, 'updated_at' => now()]);

        return Response::json(['success' => true, 'message' => $count . ' symbol(s) ' . ($request->follow ? 'followed' : 'unfollowed')], 200);
    }

    /**
     * Inline edit of just the buy price / stop loss. Both are always sent (the unchanged one too) so the
     * "stop loss below buy price" rule can be checked; empty = clear it.
     */
    public function itemPrices(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $item = WatchlistItem::find($request->id);
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Symbol not found'], 200);
        }

        $buy = $this->nullableNumber($request->buy_price);
        $sl  = $this->nullableNumber($request->stop_loss);

        foreach (['buy_price' => $buy, 'stop_loss' => $sl] as $field => $value) {
            $raw = $request->{$field};
            if ($raw !== null && trim((string) $raw) !== '' && $value === null) {
                return Response::json(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' must be a number'], 200);
            }
            if ($value !== null && $value < 0) {
                return Response::json(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' cannot be negative'], 200);
            }
        }
        if ($buy !== null && $sl !== null && $sl >= $buy) {
            return Response::json(['success' => false, 'message' => 'Stop loss must be below the buy price'], 200);
        }

        $item->buy_price = $buy;
        $item->stop_loss = $sl;
        $item->save();

        return Response::json(['success' => true, 'message' => 'Saved', 'buy_price' => $buy, 'stop_loss' => $sl], 200);
    }

    /**
     * Upload NSE's "IPO Past Issue" CSV into the IPO watchlist. This exact format only; symbols already in the
     * list are left untouched, new ones are added.
     */
    public function ipoUpload(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'watchlist_id' => 'required|integer|exists:watchlists,id',
            'file'         => 'required|file|max:2048',
            'from'         => 'nullable|date_format:Y-m-d',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $list = Watchlist::find($request->watchlist_id);
        if (strcasecmp($list->name, 'IPO') !== 0) {
            return Response::json(['success' => false, 'message' => 'The NSE IPO file can only be uploaded into the IPO watchlist'], 200);
        }

        if (!in_array(strtolower($request->file('file')->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            return Response::json(['success' => false, 'message' => 'Please upload the .csv file downloaded from NSE'], 200);
        }

        try {
            $result = NseIpoCsvImporter::import($request->file('file')->getRealPath(), $list, $request->from ?: '2023-01-01');
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 200);
        }

        // group the skipped rows by reason, so the answer stays readable
        $skipped = collect($result['skipped'])
            ->groupBy(fn ($s) => preg_replace('/\d{4}-\d{2}-\d{2}/', '…', $s['reason']))
            ->map(fn ($items, $reason) => ['reason' => $reason, 'count' => $items->count(), 'symbols' => $items->pluck('symbol')->take(40)->values()])
            ->values();

        return Response::json([
            'success'  => true,
            'message'  => "{$result['added']} added, {$result['existing']} already in the list",
            'read'     => $result['read'],
            'added'    => $result['added'],
            'existing' => $result['existing'],
            'skipped'  => $skipped,
            'skipped_count' => count($result['skipped']),
            // symbols whose listing date was not in the file (issue end + 3 days was used)
            'estimated' => $result['estimated'],
        ], 200);
    }

    public function itemDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $item = WatchlistItem::find($request->id);
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Symbol not found'], 200);
        }

        $item->delete();
        return Response::json(['success' => true, 'message' => 'Symbol removed'], 200);
    }

    /**
     * Add many symbols at once from a textarea — one per line:
     *     SYMBOL
     *     SYMBOL, buy price
     *     SYMBOL, buy price, stop loss      (comma or tab separated)
     * A pasted "NSE:INFY" / "BSE:INFY" prefix overrides the selected exchange for that line.
     * Symbols already in the watchlist are skipped, not overwritten.
     */
    public function bulkAdd(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'watchlist_id' => 'required|integer|exists:watchlists,id',
            'exchange'     => 'nullable|in:NSE,BSE',
            'follow'       => 'nullable|in:0,1',
            'text'         => 'required|string',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $lines = preg_split('/\R/', (string) $request->text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        if (count($lines) > self::MAX_BULK_LINES) {
            return Response::json(['success' => false, 'message' => 'Too many lines (max ' . self::MAX_BULK_LINES . ' at a time)'], 200);
        }

        $default_exchange = $request->exchange ?: 'NSE';
        $existing = WatchlistItem::where('watchlist_id', $request->watchlist_id)->get()
            ->mapWithKeys(fn ($i) => [$i->exchange . ':' . $i->symbol => true])->all();

        $added   = 0;
        $skipped = [];   // [['line' => ..., 'reason' => ...]]

        DB::transaction(function () use ($lines, $request, $default_exchange, &$existing, &$added, &$skipped) {
            foreach ($lines as $line) {
                $parts  = array_map('trim', preg_split('/[,\t]/', $line));
                $symbol = $this->cleanSymbol($parts[0]);
                $exchange = $default_exchange;

                // "NSE:INFY" → exchange + symbol
                if (preg_match('/^(NSE|BSE):(.+)$/i', $symbol, $m)) {
                    $exchange = strtoupper($m[1]);
                    $symbol   = $this->cleanSymbol($m[2]);
                }

                if ($symbol === '' || !preg_match($this->symbolPattern(), $symbol)) {
                    $skipped[] = ['line' => $line, 'reason' => 'Not a valid symbol'];
                    continue;
                }

                $buy = $this->nullableNumber($parts[1] ?? null);
                $sl  = $this->nullableNumber($parts[2] ?? null);

                if ((isset($parts[1]) && $parts[1] !== '' && $buy === null) || (isset($parts[2]) && $parts[2] !== '' && $sl === null)) {
                    $skipped[] = ['line' => $line, 'reason' => 'Buy price / stop loss is not a number'];
                    continue;
                }
                if ($buy !== null && $sl !== null && $sl >= $buy) {
                    $skipped[] = ['line' => $line, 'reason' => 'Stop loss must be below the buy price'];
                    continue;
                }

                $key = $exchange . ':' . $symbol;
                if (isset($existing[$key])) {
                    $skipped[] = ['line' => $line, 'reason' => 'Already in this watchlist'];
                    continue;
                }

                WatchlistItem::create([
                    'watchlist_id' => $request->watchlist_id, 'symbol' => $symbol, 'exchange' => $exchange,
                    'buy_price' => $buy, 'stop_loss' => $sl, 'follow' => $request->follow ? 1 : 0,
                ]);
                $existing[$key] = true;
                $added++;
            }
        });

        return Response::json([
            'success' => true,
            'message' => "{$added} added, " . count($skipped) . ' skipped',
            'added'   => $added,
            'skipped' => $skipped,
        ], 200);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function cleanSymbol($value): string
    {
        return strtoupper(trim((string) $value));
    }

    // Zerodha tradingsymbols: letters, digits and & - . _ ; index names (e.g. "NIFTY BANK") contain a space
    private function symbolPattern(): string
    {
        return '/^[A-Z0-9&\-\._ ]{1,50}$/';
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null || trim((string) $value) === '') return null;
        $clean = str_replace(',', '', trim((string) $value));
        return is_numeric($clean) ? (float) $clean : null;
    }
}
