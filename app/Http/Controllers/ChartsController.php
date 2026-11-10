<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use App\Models\User;
use App\Models\PortfolioBucket;
use App\Models\PortfolioHolding;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Models\ChartView;
use App\Services\CandleStats;
use App\Services\CandleSyncService;
use App\Services\CandleCleaner;
use App\Services\FibService;
use App\Services\Indicators;

class ChartsController extends Controller
{
    const DEFAULT_BARS = 200;
    const RSI_PERIOD = 14;       // the chart's RSI: the usual 14, with a 20-period average of it (the Stock Scanner has its own RSI 9)
    const RSI_AVG_PERIOD = 20;
    const RSI_WARMUP = 300;   // extra candles before the shown ones, so the RSI has settled

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * Charts page. The query string picks what to show, e.g.
     *   /charts?bucket=3   /charts?bucket=all   /charts?watchlist=2   /charts?symbol=INFY&exchange=NSE
     */
    public function index(Request $request)
    {
        return Inertia::render('Charts/Index', [
            'initial'    => [
                'bucket'    => $request->query('bucket'),
                'watchlist' => $request->query('watchlist'),
                'symbol'    => $request->query('symbol'),
                'exchange'  => $request->query('exchange', 'NSE'),
            ],
            'buckets'    => PortfolioBucket::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'watchlists' => Watchlist::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ])->withViewData(['sidebar' => 'charts']);
    }

    // ─── API: which symbols to draw ──────────────────────────────────────────

    /**
     * The symbols for one source (cheap — no candles). Each one carries the price lines to draw
     * (your average price, watchlist buy price / stop loss) and what candle data we already hold.
     */
    public function symbols(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'source'   => 'required|in:bucket,watchlist,symbol',
            'id'       => 'nullable',
            'symbol'   => 'required_if:source,symbol|nullable|string|max:50',
            'exchange' => 'nullable|in:NSE,BSE',
            'follow_only' => 'nullable|boolean',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $holdings = PortfolioHolding::all();
        $items    = WatchlistItem::all();
        $bucket_names = PortfolioBucket::pluck('name', 'id');

        // a symbol is "followed" when ANY portfolio holding or watchlist row for it is followed
        $followed = [];
        foreach ($holdings->where('follow', 1) as $h) $followed[$h->exchange . ':' . $h->zerodha_symbol] = true;
        foreach ($items->where('follow', 1) as $i)    $followed[$i->exchange . ':' . $i->symbol] = true;

        // the star rating lives on watchlist rows; a symbol in several lists shows the highest one
        $ratings = [];
        foreach ($items as $i) {
            if ($i->rating !== null) $ratings[$i->exchange . ':' . $i->symbol] = max($ratings[$i->exchange . ':' . $i->symbol] ?? 0, (int) $i->rating);
        }

        // where each symbol lives. A chart can only be removed from here when it is in a watchlist and in NO portfolio bucket
        // (any holding row counts — owned, tracking-only or an empty placeholder — because the chart would simply stay visible).
        $in_portfolio = [];
        foreach ($holdings as $h) $in_portfolio[$h->exchange . ':' . $h->zerodha_symbol] = true;
        $list_names = Watchlist::pluck('name', 'id');
        $in_watchlists = [];
        foreach ($items as $i) $in_watchlists[$i->exchange . ':' . $i->symbol][$i->watchlist_id] = $list_names[$i->watchlist_id] ?? '—';

        // Kite instrument tokens captured while fetching LTP — needed to open the symbol's chart on Kite
        $tokens = [];
        foreach ($holdings as $h) if ($h->instrument_token) $tokens[$h->exchange . ':' . $h->zerodha_symbol] = (int) $h->instrument_token;
        foreach ($items as $i)    if ($i->instrument_token) $tokens[$i->exchange . ':' . $i->symbol] ??= (int) $i->instrument_token;

        // every symbol we know about → the price lines we can draw for it
        $marks = [];
        foreach ($holdings->where('is_tracking', 0)->where('quantity', '>', 0) as $h) {
            $marks[$h->exchange . ':' . $h->zerodha_symbol][] = [
                'label' => 'Avg ' . ($bucket_names[$h->bucket_id] ?? ''), 'price' => (float) $h->avg_price, 'color' => '#2E6BFF',
            ];
        }
        foreach ($items as $i) {
            $key = $i->exchange . ':' . $i->symbol;
            if ($i->buy_price !== null) $marks[$key][] = ['label' => 'Buy', 'price' => (float) $i->buy_price, 'color' => '#0E9F5B'];
            if ($i->stop_loss !== null) $marks[$key][] = ['label' => 'Stop loss', 'price' => (float) $i->stop_loss, 'color' => '#E03131'];
        }

        // pick the symbols for the requested source
        $picked = [];   // 'NSE:INFY' => ['exchange' => , 'symbol' => ]
        $add = function ($exchange, $symbol) use (&$picked) { $picked[$exchange . ':' . $symbol] = ['exchange' => $exchange, 'symbol' => $symbol]; };

        if ($request->source === 'bucket') {
            $list = $request->id === 'all' ? $holdings : $holdings->where('bucket_id', (int) $request->id);
            foreach ($list as $h) $add($h->exchange, $h->zerodha_symbol);
        } elseif ($request->source === 'watchlist') {
            $list = $request->id === 'all' ? $items : $items->where('watchlist_id', (int) $request->id);
            foreach ($list as $i) $add($i->exchange, $i->symbol);
        } else {
            $add($request->exchange ?: 'NSE', strtoupper(trim($request->symbol)));
        }

        // "followed only" narrows a bucket / watchlist view. A single explicitly requested symbol is always shown.
        $total = count($picked);
        if ($request->boolean('follow_only') && $request->source !== 'symbol') {
            $picked = array_filter($picked, fn ($p, $key) => isset($followed[$key]), ARRAY_FILTER_USE_BOTH);
        }

        // what candle data do we hold for them?
        $stored = CandleStats::forSymbols(array_values($picked));
        $fibs   = FibService::chartLines(array_values($picked));   // shown Fibonacci swings → price lines per symbol

        $out = collect($picked)->map(function ($p, $key) use ($marks, $fibs, $stored, $followed, $ratings, $in_portfolio, $in_watchlists, $tokens) {
            return [
                'key'        => $key,
                'exchange'   => $p['exchange'],
                'symbol'     => $p['symbol'],
                'marks'      => $marks[$key] ?? [],
                'fibs'       => $fibs[$key] ?? [],
                'followed'   => isset($followed[$key]),
                'rating'     => $ratings[$key] ?? null,
                'instrument_token' => $tokens[$key] ?? null,
                'watchlists' => array_values($in_watchlists[$key] ?? []),
                'in_portfolio' => isset($in_portfolio[$key]),
                'can_remove' => isset($in_watchlists[$key]) && !isset($in_portfolio[$key]),
                'has_data'   => ($stored[$key]['daily'] ?? 0) > 0,
                'last_date'  => $stored[$key]['last'] ?? null,
            ];
        })->sortBy('symbol')->values();

        return Response::json(['success' => true, 'symbols' => $out, 'total' => $total, 'sync' => CandleSyncService::status()], 200);
    }

    // ─── API: candles for one chart ──────────────────────────────────────────

    /**
     * The last N candles of one symbol/timeframe, plus the indicator lines drawn on top.
     * Daily: SMA20, Bollinger bands, SMA50.   Weekly: SMA20, Bollinger bands, SMA40, SMA10.
     * Monthly: SMA20, Bollinger bands, SMA10 (a monthly SMA40/50 needs 3–4 years of history, so it is mostly empty).
     */
    public function candles(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'symbol'    => 'required|string|max:50',
            'exchange'  => 'nullable|in:NSE,BSE',
            'timeframe' => 'required|in:daily,weekly,monthly',
            'bars'      => 'nullable|integer|min:20|max:1000',
            'rsi'       => 'nullable|boolean',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $bars = (int) ($request->bars ?: self::DEFAULT_BARS);

        // newest N, then back into chronological order. Indicators are stored with the candle,
        // so even the first visible candle already has its full moving-average history behind it.
        // RSI is smoothed over the whole history, so it is computed over more candles than are shown and then cut to the shown ones
        $withRsi = $request->boolean('rsi');
        $rows = DB::table('stock_candles')
            ->where('exchange', $request->exchange ?: 'NSE')
            ->where('symbol', strtoupper(trim($request->symbol)))
            ->where('timeframe', $request->timeframe)
            ->orderByDesc('date')->limit($bars + ($withRsi ? self::RSI_WARMUP : 0))->get()->reverse()->values();

        $rsi = null;
        if ($withRsi) {
            $r = Indicators::rsi($rows->map(fn ($x) => (float) $x->close)->all(), self::RSI_PERIOD);
            $cut = fn (array $v) => array_map(fn ($x) => $x !== null ? round($x, 2) : null, array_slice($v, -min($bars, count($v))));
            $rsi = ['period' => self::RSI_PERIOD, 'avg_period' => self::RSI_AVG_PERIOD, 'rsi' => $cut($r), 'avg' => $cut(Indicators::sma($r, self::RSI_AVG_PERIOD))];
            $rows = $rows->slice(-min($bars, $rows->count()))->values();
        }

        $col = fn ($name) => $rows->map(fn ($r) => $r->{$name} !== null ? (float) $r->{$name} : null)->all();

        $lines = [
            ['name' => 'SMA20', 'data' => $col('sma20'),   'color' => '#2E6BFF'],
            ['name' => 'BB low',  'data' => $col('bb_low'),  'color' => '#9AA4B2'],
            ['name' => 'BB high', 'data' => $col('bb_high'), 'color' => '#9AA4B2'],
        ];
        if ($request->timeframe === 'monthly') {
            $lines[] = ['name' => 'SMA10', 'data' => $col('sma10'), 'color' => '#D6409F'];
        } elseif ($request->timeframe === 'weekly') {
            $lines[] = ['name' => 'SMA40', 'data' => $col('sma40'), 'color' => '#E03131'];
            $lines[] = ['name' => 'SMA10', 'data' => $col('sma10'), 'color' => '#D6409F'];
        } else {
            $lines[] = ['name' => 'SMA50', 'data' => $col('sma50'), 'color' => '#E03131'];
        }

        return Response::json([
            'success'     => true,
            'timeframe'   => $request->timeframe,
            'x_axis'      => $rows->pluck('date')->all(),
            // ECharts candlestick order: [open, close, low, high]
            'candles'     => $rows->map(fn ($r) => [(float) $r->open, (float) $r->close, (float) $r->low, (float) $r->high])->all(),
            'volumes'     => $rows->map(fn ($r) => (int) $r->volume)->all(),
            'volume_ma21' => $rows->map(fn ($r) => $r->volume_ma21 !== null ? (int) $r->volume_ma21 : null)->all(),
            'lines'       => $lines,
            'rsi'         => $rsi,
        ], 200);
    }

    // ─── API: remember that a chart was looked at ────────────────────────────

    /**
     * Called by the chart popup once a symbol has been on screen for a moment. Stamps "last viewed" for that
     * exchange:symbol; the Portfolio / Watchlist show it and can filter on "not viewed today".
     */
    public function viewed(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'symbol'   => 'required|string|max:50',
            'exchange' => 'nullable|in:NSE,BSE',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $view = ChartView::updateOrCreate(
            ['exchange' => $request->exchange ?: 'NSE', 'symbol' => strtoupper(trim($request->symbol))],
            ['last_viewed_at' => now()]
        );

        return Response::json(['success' => true, 'date' => $view->last_viewed_at->toDateString()], 200);
    }

    // ─── API: follow / unfollow from a chart ─────────────────────────────────

    /**
     * Give a symbol 1–5 stars (0 clears it), from the watchlist or its chart. The rating belongs to the symbol, so it is set on
     * every watchlist row it has — the same way follow is — and a symbol that is in no watchlist cannot be rated.
     */
    public function rate(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'symbol'   => 'required|string|max:50',
            'exchange' => 'nullable|in:NSE,BSE',
            'rating'   => 'required|integer|between:0,5',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $exchange = $request->exchange ?: 'NSE';
        $symbol   = strtoupper(trim($request->symbol));
        $rating   = (int) $request->rating ?: null;

        $count = WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->update(['rating' => $rating, 'updated_at' => now()]);
        if ($count === 0) {
            return Response::json(['success' => false, 'message' => "{$exchange}:{$symbol} is not in any watchlist — add it to a watchlist to rate it"], 200);
        }

        return Response::json(['success' => true, 'message' => $rating ? "Rated {$rating} / 5" : 'Rating cleared', 'rating' => $rating], 200);
    }

    /**
     * Star or unstar a symbol straight from its chart. A symbol can sit in several portfolio buckets and
     * watchlists, so the flag is set on every one of them — that is what keeps "followed" consistent.
     */
    public function follow(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'symbol'   => 'required|string|max:50',
            'exchange' => 'nullable|in:NSE,BSE',
            'follow'   => 'required|in:0,1',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $exchange = $request->exchange ?: 'NSE';
        $symbol   = strtoupper(trim($request->symbol));
        $values   = ['follow' => (int) $request->follow, 'updated_at' => now()];

        $count = PortfolioHolding::where('exchange', $exchange)->where('zerodha_symbol', $symbol)->update($values)
               + WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->update($values);

        if ($count === 0) {
            return Response::json(['success' => false, 'message' => "{$exchange}:{$symbol} is not in the portfolio or any watchlist — add it first to follow it"], 200);
        }

        return Response::json(['success' => true, 'message' => $request->follow ? 'Followed' : 'Unfollowed'], 200);
    }

    // ─── API: remove a watchlist-only symbol from the chart list ─────────────

    /**
     * Delete a symbol from every watchlist it is in — only allowed when it is NOT in the portfolio.
     * (The button is hidden otherwise, but the rule is enforced here too.) Stored candles are kept.
     */
    public function remove(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'symbol'   => 'required|string|max:50',
            'exchange' => 'nullable|in:NSE,BSE',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $exchange = $request->exchange ?: 'NSE';
        $symbol   = strtoupper(trim($request->symbol));

        if (PortfolioHolding::where('exchange', $exchange)->where('zerodha_symbol', $symbol)->exists()) {
            return Response::json(['success' => false, 'message' => "{$exchange}:{$symbol} is in the portfolio — it can only be removed from there"], 200);
        }

        $count = WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->delete();
        if ($count === 0) {
            return Response::json(['success' => false, 'message' => "{$exchange}:{$symbol} is not in any watchlist"], 200);
        }

        return Response::json(['success' => true, 'message' => "{$symbol} removed from {$count} watchlist" . ($count === 1 ? '' : 's')], 200);
    }

    // ─── API: candle sync ────────────────────────────────────────────────────

    public function sync(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        // optional: refresh just one symbol (e.g. right after adding it)
        $symbol = $request->symbol ? strtoupper(trim($request->symbol)) : null;
        if ($symbol !== null && !preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) {
            return Response::json(['success' => false, 'message' => 'Invalid symbol'], 200);
        }

        $tok = \App\Services\KiteService::syncToken();   // the python sync reads the token from app_settings: make it the newest one
        if (!$tok['ok']) return Response::json(['success' => false, 'message' => $tok['message']], 200);

        // quick = a named symbol, only the days after its last stored one (the chart popup's Sync: today's candle, a few seconds)
        $result = CandleSyncService::startInBackground($symbol, $request->boolean('force'), $symbol !== null && $request->boolean('quick'));

        return Response::json(['success' => $result['started'], 'message' => $result['message']], 200);
    }

    /**
     * Clear stored candles of symbols that are not in the portfolio, any watchlist or any paper trade.
     * Without `confirm` it only reports what WOULD be removed (a preview); with it, it removes.
     */
    public function clearUnused(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        if (CandleSyncService::isRunning()) {
            return Response::json(['success' => false, 'message' => 'A candle sync is running — wait for it to finish before clearing candles'], 200);
        }

        if (!$request->boolean('confirm')) {
            $unused = CandleCleaner::unused();
            return Response::json([
                'success' => true, 'preview' => true,
                'symbols' => count($unused), 'rows' => array_sum(array_column($unused, 'rows')),
                'list'    => array_slice(array_map(fn ($u) => $u['exchange'] . ':' . $u['symbol'], $unused), 0, 40),
            ], 200);
        }

        $done = CandleCleaner::clear();

        return Response::json([
            'success' => true, 'preview' => false, 'symbols' => $done['symbols'], 'rows' => $done['rows'],
            'message' => $done['symbols'] ? "Removed {$done['rows']} candle rows of {$done['symbols']} unused symbol" . ($done['symbols'] === 1 ? '' : 's') : 'Nothing to clear — every stored symbol is in use',
        ], 200);
    }

    public function syncStatus(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json(['success' => true] + CandleSyncService::status(), 200);
    }
}
