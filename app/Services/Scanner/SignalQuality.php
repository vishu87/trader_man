<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\DB;
use App\Services\DayCandles;

/**
 * How good is a scanner signal AS AN ENTRY — no profit and loss, no exit. For each signal it measures what the chart looked like at the
 * close of the signal's 5-minute candle (RSI, distance from VWAP, how far the stock has already run today …), what the daily chart looked
 * like the day before (trend, RSI, the last 5 days) and the monthly picture (last month, last 3 months, the monthly Bollinger band),
 * then checks those against thresholds:
 *
 *   timing checks  (the 5-minute candle)  fail → "Watch": the stock is fine, the moment is not. The next 5-minute candle of the day on which
 *                                         every timing check passes is reported as the entry that was waited for.
 *   context checks (daily and monthly)    fail → "Skip": not worth waiting for.
 *   everything passes                     → "Enter now".
 *
 * Everything is read from candles that had CLOSED at the moment it describes — nothing looks ahead.
 * Pure arithmetic apart from load(): candles and signals can be handed in.
 *
 * Candle row  = [datetime, open, high, low, close, volume] (5-minute)      Daily row = [date, open, high, low, close, volume]
 */
class SignalQuality
{
    /** The thresholds (all editable on the page). */
    const DEFAULTS = [
        'entry_from'    => '09:15',   // earliest candle to enter on
        'entry_until'   => '15:15',   // latest
        'max_day_chg'   => 8.0,       // % above the previous close: more than this = already run too far today
        'max_from_low'  => 6.0,       // % above the day's low so far
        'max_vwap_dist' => 3.0,       // % above the day's VWAP (and the price must be above it)
        'max_rsi'       => 75.0,      // 5-minute RSI(14): above this = overbought
        'min_vol_ratio' => 1.0,       // this candle's volume ÷ the average of the 20 candles before it
        'min_rsi'       => 45.0,      // 5-minute RSI: below this the move has no strength yet
        'daily_uptrend' => true,      // yesterday's close above the daily 20 SMA …
        'd_green_days'  => 5,         // … AND at least d_green_min of the last d_green_days daily candles are green (close above open): a stock above its
        'd_green_min'   => 3,         //     20 SMA only because of one old spike, now sliding down in red candles, is not an uptrend
        'max_chg_5d'    => 15.0,      // % rise over the last 5 daily closes
        'max_daily_rsi' => 80.0,      // daily RSI(14)
        'max_chg_1m'    => 60.0,      // % rise over the last completed month
    ];

    /** The checks, in the order shown: [code, group, label, short label]. */
    const CHECKS = [
        ['green',    'timing',  'Candle closed green', 'Not green'],
        ['day_chg',  'timing',  'Not up too much today', 'Up too much'],
        ['from_low', 'timing',  'Not too far off the day\'s low', 'Far off the low'],
        ['vwap',     'timing',  'Above VWAP, not stretched from it', 'VWAP'],
        ['rsi_hi',   'timing',  '5-min RSI not overbought', 'RSI high'],
        ['rsi_lo',   'timing',  '5-min RSI has strength', 'RSI low'],
        ['volume',   'timing',  'Volume picking up', 'Low volume'],
        ['window',   'timing',  'Inside the entry time window', 'Time'],
        ['d_trend',  'context', 'Daily uptrend (above 20 SMA, mostly green candles)', 'Daily trend'],
        ['d_chg5',   'context', 'Not up too much in 5 days', '5-day run'],
        ['d_rsi',    'context', 'Daily RSI not overbought', 'Daily RSI'],
        ['m_chg1',   'context', 'Not up too much last month', 'Month run'],
    ];

    /**
     * The signals a backtest should trade once the quality filter is on.
     *   mode 'enter': only the signals rated "Enter now", entered on their own candle;
     *   mode 'wait' : those, plus the "Watch" signals that later reached a good entry the same day — each becomes a signal AT that later
     *                 candle (the original time is kept as orig_signal_at); Skip / no data / never-good signals are dropped.
     * @param array[] $signals the scanner signals  @param array[] $rows evaluateAll() of them, same order
     * @return array{0: array[], 1: array} [signals to trade, counts]
     */
    public static function filterSignals(array $signals, array $rows, string $mode): array
    {
        $out = [];
        $c = ['signals' => count($signals), 'enter' => 0, 'waited' => 0, 'dropped' => 0, 'mode' => $mode];
        foreach ($signals as $i => $s) {
            $r = $rows[$i] ?? null;
            if ($r && $r['verdict'] === 'enter') {
                $out[] = $s; $c['enter']++;
            } elseif ($r && $mode === 'wait' && $r['verdict'] === 'watch' && $r['found']) {
                $s['orig_signal_at'] = $s['signal_at'];
                $s['signal_at'] = substr($s['signal_at'], 0, 10) . ' ' . $r['found']['t'] . ':00';
                $s['trigger_price'] = null;
                $out[] = $s; $c['waited']++;
            } else {
                $c['dropped']++;
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['signal_at'], $b['signal_at']) ?: strcmp($a['symbol'], $b['symbol']));
        return [$out, $c];
    }

    // ─── thresholds ──────────────────────────────────────────────────────────

    public static function normalize(array $in): array
    {
        $d = self::DEFAULTS;
        $p = array_merge($d, array_intersect_key($in, $d));
        $num  = fn ($v, $def, $min, $max) => ($v === null || $v === '' || !is_numeric($v)) ? $def : max($min, min($max, (float) $v));
        $time = fn ($v, $def) => (is_string($v) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v)) ? sprintf('%02d:%s', (int) explode(':', $v)[0], explode(':', $v)[1]) : $def;

        $p['entry_from']    = $time($p['entry_from'], $d['entry_from']);
        $p['entry_until']   = $time($p['entry_until'], $d['entry_until']);
        $p['max_day_chg']   = $num($p['max_day_chg'], $d['max_day_chg'], 0, 100);
        $p['max_from_low']  = $num($p['max_from_low'], $d['max_from_low'], 0, 100);
        $p['max_vwap_dist'] = $num($p['max_vwap_dist'], $d['max_vwap_dist'], 0, 100);
        $p['max_rsi']       = $num($p['max_rsi'], $d['max_rsi'], 1, 100);
        $p['min_rsi']       = $num($p['min_rsi'], $d['min_rsi'], 0, 99);
        $p['min_vol_ratio'] = $num($p['min_vol_ratio'], $d['min_vol_ratio'], 0, 100);
        $p['daily_uptrend'] = filter_var($p['daily_uptrend'], FILTER_VALIDATE_BOOLEAN);
        $p['d_green_days']  = (int) $num($p['d_green_days'], $d['d_green_days'], 1, 10);
        $p['d_green_min']   = (int) $num($p['d_green_min'], $d['d_green_min'], 0, $p['d_green_days']);
        $p['max_chg_5d']    = $num($p['max_chg_5d'], $d['max_chg_5d'], 0, 1000);
        $p['max_daily_rsi'] = $num($p['max_daily_rsi'], $d['max_daily_rsi'], 1, 100);
        $p['max_chg_1m']    = $num($p['max_chg_1m'], $d['max_chg_1m'], 0, 1000);
        return $p;
    }

    // ─── loading ─────────────────────────────────────────────────────────────

    /**
     * Rate every signal. Reads the stored candles one stock at a time (the daily history is long, so this keeps memory small).
     * @param array[] $signals rows of ScannerData::signals()
     * @return array[] one result per signal, in signal order
     */
    public static function evaluateAll(array $signals, array $params): array
    {
        $p = self::normalize($params);
        $by = [];
        foreach ($signals as $i => $s) $by[$s['symbol']][] = $i;

        $out = [];
        foreach ($by as $symbol => $idxs) {
            $days = array_unique(array_map(fn ($i) => substr($signals[$i]['signal_at'], 0, 10), $idxs));
            sort($days);

            // 5-minute candles: each signal day plus the 6 days before it (indicator history); daily: three years before the first signal
            $ranges = [];
            foreach ($days as $d) {
                $a = date('Y-m-d', strtotime($d . ' -6 days'));
                $n = count($ranges);
                if ($n && $a <= $ranges[$n - 1][1]) $ranges[$n - 1][1] = $d; else $ranges[] = [$a, $d];
            }
            $five = [];
            $q = DB::table('scanner_candles')->where('exchange', 'NSE')->where('symbol', $symbol)->where('timeframe', '5min')
                ->where(function ($w) use ($ranges) { foreach ($ranges as [$a, $b]) $w->orWhereBetween('candle_at', [$a . ' 00:00:00', $b . ' 23:59:59']); })
                ->orderBy('candle_at')->select('candle_at', 'open', 'high', 'low', 'close', 'volume');
            foreach ($q->cursor() as $r) $five[] = [$r->candle_at, (float) $r->open, (float) $r->high, (float) $r->low, (float) $r->close, (int) $r->volume];

            $daily = [];
            $q = DB::table('scanner_candles')->where('exchange', 'NSE')->where('symbol', $symbol)->where('timeframe', 'day')
                ->whereBetween('candle_at', [date('Y-m-d', strtotime($days[0] . ' -900 days')) . ' 00:00:00', end($days) . ' 23:59:59'])
                ->orderBy('candle_at')->select('candle_at', 'open', 'high', 'low', 'close', 'volume');
            foreach ($q->cursor() as $r) $daily[] = [substr($r->candle_at, 0, 10), (float) $r->open, (float) $r->high, (float) $r->low, (float) $r->close, (int) $r->volume];

            $series = self::prepare($five);
            $dcache = [];
            foreach ($idxs as $i) $out[$i] = self::evaluate($signals[$i], $series, $daily, $dcache, $p);
        }

        ksort($out);
        return array_values($out);
    }

    // ─── one stock's 5-minute series: every indicator at every candle, once ──

    /** @return array{c: array, rsi: array, vwap: array, sma20: array, volAvg: array, dayOpen: array, dayLow: array, volSoFar: array, sessionFirst: array, ts: array} */
    public static function prepare(array $c): array
    {
        $n = count($c);
        $closes = array_column($c, 4);
        $rsi = DayCandles::rsi($closes);

        $S = ['c' => $c, 'rsi' => $rsi, 'vwap' => [], 'sma20' => [], 'volAvg' => [], 'dayOpen' => [], 'dayLow' => [], 'volSoFar' => [], 'sessionFirst' => [], 'ts' => []];
        $day = null; $pv = 0.0; $vol = 0.0; $low = 0.0; $open = 0.0; $first = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = substr($c[$i][0], 0, 10);
            if ($d !== $day) { $day = $d; $pv = 0.0; $vol = 0.0; $low = $c[$i][3]; $open = $c[$i][1]; $first = $i; }
            $pv += (($c[$i][2] + $c[$i][3] + $c[$i][4]) / 3) * $c[$i][5];
            $vol += $c[$i][5];
            $low = min($low, $c[$i][3]);
            $S['vwap'][$i] = $vol > 0 ? $pv / $vol : null;
            $S['dayOpen'][$i] = $open; $S['dayLow'][$i] = $low; $S['volSoFar'][$i] = $vol; $S['sessionFirst'][$i] = $first;
            $S['ts'][$c[$i][0]] = $i;

            $S['sma20'][$i] = $i >= 19 ? array_sum(array_slice($closes, $i - 19, 20)) / 20 : null;
            $S['volAvg'][$i] = $i >= 20 ? array_sum(array_column(array_slice($c, $i - 20, 20), 5)) / 20 : null;
        }
        return $S;
    }

    // ─── the verdict for one signal ──────────────────────────────────────────

    public static function evaluate(array $sig, array $S, array $daily, array &$dcache, array $p): array
    {
        $date = substr($sig['signal_at'], 0, 10);
        $key  = Backtester::candleKey($sig['signal_at']);
        $row  = ['signal_at' => $sig['signal_at'], 'symbol' => $sig['symbol'], 'cap' => $sig['market_cap'] ?? null, 'candle' => substr($key, 11, 5)];

        $i = $S['ts'][$key] ?? null;
        if ($i === null) return $row + ['verdict' => 'nodata', 'score' => null, 'fails' => [], 'na' => [], 'f' => null, 'found' => null, 'note' => 'no 5-minute candle for this signal'];

        $ctx = $dcache[$date] ??= self::context($daily, $date);
        $f = self::features($S, $i, $ctx);
        [$res, $fails, $na] = self::judge($f, $ctx, $p);

        $timing = array_filter($fails, fn ($code) => self::group($code) === 'timing');
        $context = array_filter($fails, fn ($code) => self::group($code) === 'context');
        $verdict = $context ? 'skip' : ($timing ? 'watch' : 'enter');

        // watch: the next candle of the day on which every timing check passes (only what had closed by then is used)
        $found = null;
        if ($verdict === 'watch') {
            $last = self::sessionLast($S, $i);
            for ($k = $i + 1; $k <= $last; $k++) {
                $fk = self::features($S, $k, $ctx);
                [, $fk2] = self::judge($fk, $ctx, $p);
                if (!array_filter($fk2, fn ($code) => self::group($code) === 'timing')) {
                    $found = ['t' => substr($S['c'][$k][0], 11, 5), 'price' => $fk['price'], 'after_min' => ($k - $i) * 5, 'vs_signal_pct' => round(($fk['price'] / $f['price'] - 1) * 100, 2)];
                    break;
                }
            }
        }

        $total = count(self::CHECKS) - count($na);
        $score = $total > 0 ? (int) round(($total - count($fails)) / $total * 100) : null;

        return $row + ['verdict' => $verdict, 'score' => $score, 'fails' => array_values($fails), 'na' => array_values($na), 'f' => $f + ['ctx' => $ctx], 'found' => $found];
    }

    private static function group(string $code): string
    {
        foreach (self::CHECKS as [$c, $g]) if ($c === $code) return $g;
        return 'timing';
    }

    private static function sessionLast(array $S, int $i): int
    {
        $d = substr($S['c'][$i][0], 0, 10);
        $n = count($S['c']);
        $k = $i;
        while ($k + 1 < $n && substr($S['c'][$k + 1][0], 0, 10) === $d) $k++;
        return $k;
    }

    // ─── features at one 5-minute candle (its close) ─────────────────────────

    private static function features(array $S, int $i, array $ctx): array
    {
        $c = $S['c'][$i];
        $price = $c[4];
        $prev = $ctx['prev_close'];
        if ($prev === null) {   // no daily history: the last close of the session before, from the 5-minute candles
            $first = $S['sessionFirst'][$i];
            $prev = $first > 0 ? $S['c'][$first - 1][4] : null;
        }
        $vwap = $S['vwap'][$i];
        $low = $S['dayLow'][$i];
        $open = $S['dayOpen'][$i];
        $sma = $S['sma20'][$i];
        $volAvg = $S['volAvg'][$i];

        return [
            'at'        => substr($c[0], 11, 5),
            'price'     => $price,
            'green'     => $c[4] > $c[1],
            'body_pct'  => $c[1] > 0 ? round(($c[4] / $c[1] - 1) * 100, 2) : null,
            'rsi'       => $S['rsi'][$i],
            'vwap_dist' => $vwap ? round(($price / $vwap - 1) * 100, 2) : null,
            'sma_dist'  => $sma ? round(($price / $sma - 1) * 100, 2) : null,
            'day_chg'   => $prev ? round(($price / $prev - 1) * 100, 2) : null,
            'gap'       => $prev ? round(($open / $prev - 1) * 100, 2) : null,
            'from_open' => $open ? round(($price / $open - 1) * 100, 2) : null,
            'from_low'  => $low ? round(($price / $low - 1) * 100, 2) : null,
            'vol_ratio' => $volAvg ? round($c[5] / $volAvg, 2) : null,
            'vol_yday'  => $ctx['prev_volume'] ? round($S['volSoFar'][$i] / $ctx['prev_volume'] * 100, 1) : null,   // the day's volume so far as % of yesterday's whole day
        ];
    }

    /** Run the checks on a candle's features. @return array{0: array, 1: string[], 2: string[]} [results, failed codes, not-available codes] */
    private static function judge(array $f, array $ctx, array $p): array
    {
        $r = [];
        $hm = $f['at'];
        $r['green']    = $f['green'];
        $r['day_chg']  = $f['day_chg'] === null ? null : $f['day_chg'] <= $p['max_day_chg'];
        $r['from_low'] = $f['from_low'] === null ? null : $f['from_low'] <= $p['max_from_low'];
        $r['vwap']     = $f['vwap_dist'] === null ? null : ($f['vwap_dist'] >= 0 && $f['vwap_dist'] <= $p['max_vwap_dist']);
        $r['rsi_hi']   = $f['rsi'] === null ? null : $f['rsi'] <= $p['max_rsi'];
        $r['rsi_lo']   = $f['rsi'] === null ? null : $f['rsi'] >= $p['min_rsi'];
        $r['volume']   = $f['vol_ratio'] === null ? null : $f['vol_ratio'] >= $p['min_vol_ratio'];
        $r['window']   = $hm >= $p['entry_from'] && $hm <= $p['entry_until'];
        if (!$p['daily_uptrend'] || $ctx['d_vs_sma20'] === null) {
            $r['d_trend'] = !$p['daily_uptrend'] ? true : null;
        } else {   // above the 20 SMA, and the last few daily candles mostly green
            $last = array_slice($ctx['d_green'], -$p['d_green_days']);
            $r['d_trend'] = $ctx['d_vs_sma20'] > 0 && count(array_filter($last)) >= min($p['d_green_min'], count($last));
        }
        $r['d_chg5']   = $ctx['d_chg5'] === null ? null : $ctx['d_chg5'] <= $p['max_chg_5d'];
        $r['d_rsi']    = $ctx['d_rsi'] === null ? null : $ctx['d_rsi'] <= $p['max_daily_rsi'];
        $r['m_chg1']   = $ctx['m_chg1'] === null ? null : $ctx['m_chg1'] <= $p['max_chg_1m'];

        $fails = []; $na = [];
        foreach (self::CHECKS as [$code]) {
            if ($r[$code] === null) $na[] = $code; elseif ($r[$code] === false) $fails[] = $code;
        }
        return [$r, $fails, $na];
    }

    // ─── the daily and monthly picture before a date ─────────────────────────

    /** From the daily candles of the sessions BEFORE $date. */
    public static function context(array $daily, string $date): array
    {
        $rows = [];
        foreach ($daily as $r) { if ($r[0] >= $date) break; $rows[] = $r; }
        $n = count($rows);
        $null = ['prev_close' => null, 'prev_volume' => null, 'd_rsi' => null, 'd_vs_sma20' => null, 'd_vs_sma50' => null, 'd_chg5' => null, 'd_chg20' => null, 'd_atr' => null,
                 'd_from_high' => null, 'd_vol_ratio' => null, 'd_green' => [], 'm_chg1' => null, 'm_chg3' => null, 'm_mtd' => null, 'm_bb' => null, 'sessions' => $n];
        if ($n < 2) return array_merge($null, ['prev_close' => $n ? $rows[$n - 1][4] : null, 'prev_volume' => $n ? $rows[$n - 1][5] : null]);

        $closes = array_column($rows, 4);
        $last = $closes[$n - 1];
        $mean = fn (array $a) => array_sum($a) / count($a);
        $pct = fn ($a, $b) => $b ? round(($a / $b - 1) * 100, 2) : null;

        $out = $null;
        $out['prev_close']  = $last;
        $out['prev_volume'] = $rows[$n - 1][5];
        $rsi = $n > 15 ? DayCandles::rsi(array_slice($closes, -120)) : [];
        $out['d_rsi'] = $rsi ? end($rsi) : null;
        $out['d_green'] = array_map(fn ($r) => $r[4] > $r[1], array_slice($rows, -10));   // the last 10 daily candles, oldest first: green = close above open
        $out['d_vs_sma20'] = $n >= 20 ? $pct($last, $mean(array_slice($closes, -20))) : null;
        $out['d_vs_sma50'] = $n >= 50 ? $pct($last, $mean(array_slice($closes, -50))) : null;
        $out['d_chg5']  = $n >= 6 ? $pct($last, $closes[$n - 6]) : null;
        $out['d_chg20'] = $n >= 21 ? $pct($last, $closes[$n - 21]) : null;
        if ($n >= 15) {   // average true range as % of the close
            $tr = [];
            for ($k = $n - 14; $k < $n; $k++) $tr[] = max($rows[$k][2] - $rows[$k][3], abs($rows[$k][2] - $rows[$k - 1][4]), abs($rows[$k][3] - $rows[$k - 1][4]));
            $out['d_atr'] = $last ? round($mean($tr) / $last * 100, 2) : null;
        }
        if ($n >= 120) $out['d_from_high'] = $pct($last, max(array_column(array_slice($rows, -250), 2)));   // % below the 52-week high (negative)
        if ($n >= 21) $out['d_vol_ratio'] = round($rows[$n - 1][5] / max(1, $mean(array_column(array_slice($rows, -21, 20), 5))), 2);

        // monthly bars from the daily closes: the last close of each calendar month
        $months = [];
        foreach ($rows as $r) $months[substr($r[0], 0, 7)] = $r[4];
        $curMonth = substr($date, 0, 7);
        $thisMonth = $months[$curMonth] ?? null;          // the month so far (sessions before $date)
        unset($months[$curMonth]);
        $mc = array_values($months);                       // completed months, oldest first
        $m = count($mc);
        if ($m >= 2) $out['m_chg1'] = $pct($mc[$m - 1], $mc[$m - 2]);
        if ($m >= 4) $out['m_chg3'] = $pct($mc[$m - 1], $mc[$m - 4]);
        if ($thisMonth !== null && $m >= 1) $out['m_mtd'] = $pct($thisMonth, $mc[$m - 1]);
        if ($m >= 20) {   // the monthly Bollinger band (20, 2) of the completed months, against the latest close
            $w = array_slice($mc, -20); $avg = $mean($w);
            $sd = sqrt($mean(array_map(fn ($x) => ($x - $avg) ** 2, $w)));
            $upper = $avg + 2 * $sd;
            $out['m_bb'] = $upper > 0 ? round(($last / $upper - 1) * 100, 2) : null;   // positive = above the monthly upper band
        }
        return $out;
    }
}
