<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use App\Models\User;
use App\Services\DayCandles;
use App\Services\Scanner\Backtester;
use App\Services\Scanner\HistoryFetcher;
use App\Services\Scanner\LeakCheck;
use App\Services\Scanner\ScannerData;
use App\Services\Scanner\SignalQuality;

/**
 * Scanner signals — BACKTESTING ONLY. The scanner's historical signals are replayed on stored 5-minute candles under rules you
 * choose, over a period you pick. Nothing here trades, and nothing here is used by the live trading code (App\Services\Live).
 */
class ScannerController extends Controller
{
    const MAX_DAYS = 400;

    public function index()
    {
        return Inertia::render('Scanner/Index')->withViewData(['sidebar' => 'scanner']);
    }

    private function fail(string $message, array $extra = [])
    {
        return Response::json(['success' => false, 'message' => $message] + $extra, 200);
    }

    /** The period asked for (default: the last month). @return array{0: string, 1: string}|string  [from, to] or an error message */
    private function period(Request $request)
    {
        $v = Validator::make($request->all(), ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        if (!$v->passes()) return $v->errors()->first();

        $to   = $request->input('to') ?: date('Y-m-d');
        $from = $request->input('from') ?: date('Y-m-d', strtotime($to . ' -1 month'));
        if ($from > $to) return 'The "from" date is after the "to" date';
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_DAYS) return 'Pick a period of at most ' . self::MAX_DAYS . ' days';

        return [$from, $to];
    }

    // ─── what is loaded, for a period ────────────────────────────────────────

    public function overview(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $p = $this->period($request);
        if (is_string($p)) return $this->fail($p);
        [$from, $to] = $p;

        $sig = DB::table('scanner_signals')->whereBetween('trade_date', [$from, $to])->selectRaw('COUNT(*) n, COUNT(DISTINCT symbol) symbols, COUNT(DISTINCT trade_date) days')->first();
        $all = DB::table('scanner_signals')->selectRaw('COUNT(*) n, MIN(trade_date) first_date, MAX(trade_date) last_date')->first();

        // stocks of this period that have 5-minute candles in it (one indexed look-up per stock: the candle table is large)
        $with5 = 0;
        if ($sig->n) {
            $symbols = DB::table('scanner_signals')->whereBetween('trade_date', [$from, $to])->distinct()->pluck('symbol');
            foreach ($symbols as $sym) {
                if (DB::table('scanner_candles')->where('exchange', 'NSE')->where('symbol', $sym)->where('timeframe', '5min')->whereBetween('candle_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->exists()) $with5++;
            }
        }

        return Response::json([
            'success'  => true,
            'from'     => $from, 'to' => $to,
            'signals'  => ['count' => (int) $sig->n, 'symbols' => (int) $sig->symbols, 'days' => (int) $sig->days],
            'available' => ['count' => (int) $all->n, 'first_date' => $all->first_date, 'last_date' => $all->last_date],
            'candles'  => ['symbols_5min' => $with5],
            'fetch'    => HistoryFetcher::status(),
            'defaults' => Backtester::DEFAULTS,
            'presets'  => ScannerData::presets(),
        ], 200);
    }

    // ─── candles from Kite ───────────────────────────────────────────────────

    /** Download the candles the signals of a period need (only what is missing), in the background. */
    public function fetch(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $p = $this->period($request);
        if (is_string($p)) return $this->fail($p);

        $tok = \App\Services\KiteService::syncToken();   // newest token first
        if (!$tok['ok']) return $this->fail($tok['message']);

        $res = HistoryFetcher::startInBackground($p[0], $p[1]);

        return $res['started'] ? Response::json(['success' => true, 'message' => $res['message']], 200) : $this->fail($res['message']);
    }

    public function fetchStatus(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json(['success' => true] + HistoryFetcher::status(), 200);
    }

    // ─── the backtest ────────────────────────────────────────────────────────

    /** Load what a request needs. @return array{0: array, 1: array, 2: string, 3: string}|string  [signals, data, from, to] or an error message */
    private function prepare(Request $request, int $holdDays)
    {
        $p = $this->period($request);
        if (is_string($p)) return $p;
        [$from, $to] = $p;

        $signals = ScannerData::signals($from, $to);
        if (!$signals) return 'There are no scanner signals between ' . $from . ' and ' . $to;

        $data = ScannerData::load($signals, $holdDays);
        if (!$data['candles']) return 'No candles for that period yet — use "Fetch candles for this period" first';

        // the signal quality filter: trade only the signals it lets through (candles are loaded for ALL of them: a superset)
        $meta = ['original' => $signals, 'quality' => null];
        $mode = (string) $request->input('params.quality_mode', 'off');
        if (in_array($mode, ['enter', 'wait'], true)) {
            $rows = SignalQuality::evaluateAll($signals, SignalQuality::normalize((array) $request->input('quality_params', [])));
            [$signals, $counts] = SignalQuality::filterSignals($signals, $rows, $mode);
            $meta['quality'] = $counts;
            if (!$signals) return 'No signal passes the quality filter in this period — loosen the thresholds on the "Signal quality" tab';
        }

        return [$signals, $data, $from, $to, $meta];
    }

    /** One strategy, in full: summary, every day, every trade. */
    public function run(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        set_time_limit(180); ini_set('memory_limit', '1536M');

        $params = (array) $request->input('params', []);
        $prep = $this->prepare($request, ScannerData::holdDays($params));
        if (is_string($prep)) return $this->fail($prep);
        [$signals, $data, $from, $to, $meta] = $prep;

        $res = ScannerData::backtest($params, $from, $to, $data, $signals);

        // does the signal list know the future? (about the scanner's data, so it ignores the page's filters)
        $leak = LeakCheck::run($meta['original'], $data['candles'], $data['daily']);

        return Response::json(['success' => true, 'leak' => $leak, 'quality' => $meta['quality']] + $res, 200);
    }

    /** Every preset strategy under the page's filters: totals and the cumulative line of each, for comparing. */
    public function compare(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        set_time_limit(240); ini_set('memory_limit', '1536M');

        $prep = $this->prepare($request, max(5, ScannerData::holdDays((array) $request->input('params', []))));
        if (is_string($prep)) return $this->fail($prep);
        [$signals, $data, $from, $to] = $prep;

        // entry / exit rules come from each preset, everything else (filters, slippage, time window) from the page
        $base = (array) $request->input('params', []);
        foreach (['first_amount', 'add_amount', 'max_adds', 'add_drop_pct', 'entry_price', 'hold_days', 'exit_at', 'stop_pct', 'target_pct'] as $k) unset($base[$k]);

        $rows = [];
        foreach (ScannerData::presets() as $preset) {
            $res = ScannerData::backtest($preset['p'] + ['strategy' => 'rules'] + $base, $from, $to, $data, $signals);
            $rows[] = ['id' => $preset['id'], 'name' => $preset['name'], 'summary' => $res['summary'], 'days' => array_map(fn ($d) => ['date' => $d['date'], 'net' => $d['net'], 'cumulative' => $d['cumulative']], $res['days'])];
        }

        return Response::json(['success' => true, 'rows' => $rows], 200);
    }

    /**
     * How good each signal is as an ENTRY (no profit and loss): the 5-minute, daily and monthly picture at the signal, checked against
     * the thresholds sent from the page. See App\Services\Scanner\SignalQuality.
     */
    public function quality(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        set_time_limit(240); ini_set('memory_limit', '1536M');

        $p = $this->period($request);
        if (is_string($p)) return $this->fail($p);
        [$from, $to] = $p;

        $signals = ScannerData::signals($from, $to);
        if (!$signals) return $this->fail('There are no scanner signals between ' . $from . ' and ' . $to);

        $params = SignalQuality::normalize((array) $request->input('params', []));
        $rows = SignalQuality::evaluateAll($signals, $params);

        $count = ['enter' => 0, 'watch' => 0, 'skip' => 0, 'nodata' => 0, 'watch_found' => 0];
        foreach ($rows as $r) { $count[$r['verdict']]++; if ($r['verdict'] === 'watch' && $r['found']) $count['watch_found']++; }

        return Response::json(['success' => true, 'rows' => $rows, 'counts' => $count, 'params' => $params, 'defaults' => SignalQuality::DEFAULTS,
            'checks' => array_map(fn ($c) => ['code' => $c[0], 'group' => $c[1], 'label' => $c[2], 'short' => $c[3]], SignalQuality::CHECKS)], 200);
    }

    /** One stock's day on 5-minute candles — the stored backtest candles first, so the chart shows what the backtest saw. The page draws its trades on top. */
    public function chart(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $symbol = strtoupper(trim((string) $request->input('symbol')));
        if (!preg_match('/^[A-Z0-9&\-\._]{1,50}$/', $symbol)) return $this->fail('Unknown stock');
        $date = (string) $request->input('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return $this->fail('Pick a date');

        $c = DayCandles::forDay($symbol, $date, true);
        if (!$c['ok']) return $this->fail($c['message']);

        return Response::json(['success' => true, 'symbol' => $symbol, 'date' => $date, 'source' => $c['source'], 'prev_close' => $c['prev_close'], 'candles' => $c['candles'], 'alerts' => [], 'fills' => [], 'lines' => []], 200);
    }

    /** What stocks do after the first signal of the day (no position rules). */
    public function behaviour(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        set_time_limit(180); ini_set('memory_limit', '1536M');

        $prep = $this->prepare($request, 1);
        if (is_string($prep)) return $this->fail($prep);
        [$signals, $data] = $prep;

        $res = (new Backtester((array) $request->input('params', [])))->behaviour($signals, $data['candles'], $data['daily'], $data['names']);

        return Response::json(['success' => true] + $res, 200);
    }
}
