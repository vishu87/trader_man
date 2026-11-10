<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use App\Models\User;
use App\Models\ZerodhaImport;
use App\Models\ZerodhaTrade;
use App\Services\ZerodhaTrades;

/**
 * Your Zerodha executions: loaded from a tradebook file you upload, or synced from Kite (today's trades; the same thing
 * `php artisan zerodha:sync-trades` does on a schedule). Partial fills of one order are shown together as one order line.
 */
class ZerodhaTradesController extends Controller
{
    const PER_PAGE = 50;

    public function index()
    {
        return Inertia::render('ZerodhaTrades/Index')->withViewData(['sidebar' => 'zerodha-trades']);
    }

    private function fail(string $message, array $extra = [])
    {
        return Response::json(['success' => false, 'message' => $message] + $extra, 200);
    }

    private function ok(string $message, array $extra = [])
    {
        return Response::json(['success' => true, 'message' => $message] + $extra, 200);
    }

    // ─── API: the list ───────────────────────────────────────────────────────

    public function list(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:50',
            'side'   => 'nullable|in:buy,sell',
            'from'   => 'nullable|date_format:Y-m-d',
            'to'     => 'nullable|date_format:Y-m-d',
            'page'   => 'nullable|integer|min:1',
            'sort'   => 'nullable|in:date,symbol,value',
            'dir'    => 'nullable|in:asc,desc',
        ]);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $filtered = fn () => self::filtered($request);

        // one line per order: partial fills of the same order are summed
        $orderKey = "COALESCE(order_id, CONCAT('t', trade_id))";
        $group    = ['exchange', 'trade_date', 'symbol', 'trade_type', DB::raw($orderKey)];

        $total = DB::query()->fromSub($filtered()->select('exchange')->groupBy($group), 'g')->count();

        $dir  = $request->input('dir') === 'asc' ? 'asc' : 'desc';
        $sort = $request->input('sort', 'date');
        $q = $filtered()->selectRaw("exchange, trade_date, symbol, trade_type, {$orderKey} AS order_key, MAX(order_id) AS order_id,
                SUM(quantity) AS qty, SUM(quantity * price) AS value, COUNT(*) AS fills, MIN(executed_at) AS first_at, MAX(executed_at) AS last_at,
                MAX(isin) AS isin, MAX(source) AS source, MAX(product) AS product")->groupBy($group);
        match ($sort) {
            'symbol' => $q->orderBy('symbol', $dir)->orderByDesc('trade_date'),
            'value'  => $q->orderBy('value', $dir)->orderByDesc('trade_date'),
            default  => $q->orderBy('trade_date', $dir)->orderBy('last_at', $dir),
        };
        $page   = max(1, (int) $request->input('page', 1));
        $orders = $q->orderBy('order_key')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        // the individual fills of just these orders
        $fills = [];
        if ($orders->isNotEmpty()) {
            $rows = ZerodhaTrade::whereIn('trade_date', $orders->pluck('trade_date')->unique())->whereIn('symbol', $orders->pluck('symbol')->unique())->orderBy('executed_at')->orderBy('id')->get();
            foreach ($rows as $f) {
                $key = $f->exchange . '|' . substr((string) $f->trade_date, 0, 10) . '|' . $f->symbol . '|' . $f->trade_type . '|' . ($f->order_id ?: 't' . $f->trade_id);
                $fills[$key][] = ['id' => $f->id, 'trade_id' => $f->trade_id, 'qty' => (int) $f->quantity, 'price' => (float) $f->price, 'at' => $f->executed_at ? substr((string) $f->executed_at, 11, 8) : null];
            }
        }

        $list = $orders->map(function ($o) use ($fills) {
            $date = substr((string) $o->trade_date, 0, 10);
            $key  = $o->exchange . '|' . $date . '|' . $o->symbol . '|' . $o->trade_type . '|' . $o->order_key;
            return [
                'key' => $key, 'date' => $date, 'time' => $o->last_at ? substr((string) $o->last_at, 11, 5) : null,
                'exchange' => $o->exchange, 'symbol' => $o->symbol, 'isin' => $o->isin, 'side' => $o->trade_type,
                'qty' => (int) $o->qty, 'avg_price' => $o->qty > 0 ? round($o->value / $o->qty, 2) : 0, 'value' => round((float) $o->value, 2),
                'fills' => (int) $o->fills, 'order_id' => $o->order_id, 'source' => $o->source, 'product' => $o->product,
                'fill_list' => $fills[$key] ?? [],
            ];
        })->values();

        return Response::json([
            'success' => true,
            'orders'  => $list,
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / self::PER_PAGE)),
            'per_page' => self::PER_PAGE,
            'summary' => self::summary($filtered()),
            'imports' => self::imports(),
        ], 200);
    }

    private static function filtered(Request $request)
    {
        $q = DB::table('zerodha_trades');
        if ($s = trim((string) $request->input('search'))) $q->where('symbol', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%');
        if ($request->filled('side')) $q->where('trade_type', $request->side);
        if ($request->filled('from')) $q->where('trade_date', '>=', $request->from);
        if ($request->filled('to'))   $q->where('trade_date', '<=', $request->to);
        return $q;
    }

    private static function summary($query): array
    {
        $r = $query->selectRaw("COUNT(*) AS fills, COUNT(DISTINCT symbol) AS symbols, MIN(trade_date) AS first_date, MAX(trade_date) AS last_date,
                COUNT(DISTINCT exchange, trade_date, symbol, trade_type, COALESCE(order_id, CONCAT('t', trade_id))) AS orders,
                COALESCE(SUM(CASE WHEN trade_type = 'buy'  THEN quantity * price END), 0) AS buy_value,
                COALESCE(SUM(CASE WHEN trade_type = 'sell' THEN quantity * price END), 0) AS sell_value")->first();

        return [
            'fills' => (int) $r->fills, 'orders' => (int) $r->orders, 'symbols' => (int) $r->symbols,
            'buy_value' => round((float) $r->buy_value, 2), 'sell_value' => round((float) $r->sell_value, 2),
            'first_date' => $r->first_date, 'last_date' => $r->last_date,
        ];
    }

    private static function imports(): array
    {
        return ZerodhaImport::orderByDesc('id')->limit(30)->get()->map(fn ($i) => [
            'id' => $i->id, 'source' => $i->source, 'file_name' => $i->file_name, 'rows_read' => $i->rows_read, 'added' => $i->added,
            'duplicates' => $i->duplicates, 'conflicts' => $i->conflicts, 'skipped' => $i->skipped,
            'first_date' => $i->first_date ? substr((string) $i->first_date, 0, 10) : null, 'last_date' => $i->last_date ? substr((string) $i->last_date, 0, 10) : null,
            'at' => $i->created_at->format('d M Y H:i'),
        ])->all();
    }

    // ─── API: upload a tradebook ─────────────────────────────────────────────

    public function upload(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        // PHP refuses a file over upload_max_filesize before Laravel sees it; say that instead of "choose a file"
        $sent = $request->file('file');
        if ($sent && !$sent->isValid()) return $this->fail($sent->getErrorMessage());

        $validator = Validator::make($request->all(), ['file' => 'required|file|max:20480'], [
            'file.required' => 'Choose the tradebook file first', 'file.file' => 'The upload did not work — try the file again',
            'file.max' => 'That file is larger than 20 MB',
        ]);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $file = $request->file('file');
        $name = substr($file->getClientOriginalName(), 0, 190);

        try {
            $parsed = ZerodhaTrades::parseFile($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        if (!$parsed['rows']) {
            return $this->fail($parsed['read'] ? 'None of the ' . $parsed['read'] . ' rows could be read. First problem: line ' . $parsed['skipped'][0]['line'] . ' — ' . $parsed['skipped'][0]['reason']
                                                  : 'The file has no trades in it', ['skipped_list' => array_slice($parsed['skipped'], 0, 10)]);
        }

        $res = ZerodhaTrades::store($parsed['rows'], 'file', $name, count($parsed['skipped']));

        return $this->ok(self::describe($name, $res), $res + ['skipped_list' => array_slice($parsed['skipped'], 0, 10)]);
    }

    private static function describe(string $what, array $res): string
    {
        $parts = [$res['added'] . ' new'];
        if ($res['duplicates']) $parts[] = $res['duplicates'] . ' already stored';
        if ($res['conflicts'])  $parts[] = $res['conflicts'] . ' differ from what is stored (left as is)';
        if ($res['skipped'])    $parts[] = $res['skipped'] . ' could not be read';

        return "{$what}: " . $res['read'] . ' rows — ' . implode(', ', $parts);
    }

    // ─── API: sync today's trades from Kite ──────────────────────────────────

    public function sync(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $tok = \App\Services\KiteService::syncToken();   // pick up the newest token first
        if (!$tok['ok']) return $this->fail($tok['message']);

        try {
            $res = ZerodhaTrades::syncToday();
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        if ($res['fetched'] === 0) return $this->ok('Kite has no executions for today yet', $res);

        return $this->ok(self::describe('Kite (today)', $res), $res);
    }

    // ─── API: undo an import ─────────────────────────────────────────────────

    public function undo(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $import = ZerodhaImport::find($request->id);
        if (!$import) return $this->fail('That import no longer exists');

        $n = ZerodhaTrades::undo($import);

        return $this->ok("Import undone — {$n} execution" . ($n === 1 ? '' : 's') . ' removed');
    }
}
