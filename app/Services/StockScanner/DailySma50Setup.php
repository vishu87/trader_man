<?php

namespace App\Services\StockScanner;

use App\Services\Indicators;

/**
 * Daily chart: a stock sitting close to its 50-day SMA, with RSI(14) turning up and a heavy-volume day.
 * Judged on the LAST daily candle (the latest complete one). Fixed rules, scored 0-100 like the other scanners (near misses keep part of the points):
 *
 *   1  (35 pts) NEAR the 50-day SMA: the close is within NEAR_FULL % of it (above or below) = full points; the points fade to nothing at NEAR_ZERO %
 *   2  (30 pts) RSI(14) moving UP: 20 pts for the rise over the last 3 days (0 = none, RISE_FULL RSI points or more = full; halved when today's RSI is not above yesterday's)
 *              and 10 pts for RSI being above its own 20-day average (the same RSI + average as the chart popup)
 *   3  (35 pts) HIGH volume: today's volume against the average of the 20 days before (1x = none, VOLUME_X = full)
 *
 * Pure: takes the candles, returns the score and the numbers behind it. The SMA, the RSI and the volume average are worked out here from the closes
 * and volumes (not read from the stored columns), so the scanner and "Check a symbol" always agree with each other.
 */
class DailySma50Setup
{
    const KEY  = 'daily_sma50_rsi_volume';
    const NAME = 'Near 50 SMA · RSI up · volume';

    const TIMEFRAME = 'daily';
    const CHART = 'Daily';
    const UNIT = 'day';
    const UNITS = 'days';
    const DATE_FMT = 'd M Y';

    const LIST_FLOOR = 75;       // only scores of 75 and up are listed (the shared floor of 50 would list a third of the watchlist for three rules)
    const LOOKBACK_DAYS = 300;   // calendar days of daily candles loaded per symbol (about 200 sessions: plenty for a 50-day SMA and a settled RSI)
    const MIN_BARS = 70;         // 50 for the SMA, 14 + 20 for the RSI and its average

    const SMA_PERIOD = 50;
    const RSI_PERIOD = 14;
    const RSI_AVG_PERIOD = 20;
    const NEAR_FULL = 3.0;       // % from the SMA that still counts as "near" (full points)
    const NEAR_ZERO = 8.0;       // % from the SMA where the points run out
    const RISE_DAYS = 3;
    const RISE_FULL = 4.0;       // RSI points gained over RISE_DAYS for the full 20
    const VOL_LOOKBACK = 20;
    const VOLUME_X = 1.5;
    const MARKET_CLOSE = '15:35';   // a candle dated today is still forming before this (the last complete one is judged then)

    /** The rules are FIXED: whatever is passed in, nothing is adjustable. */
    public static function normalize(array $in = []): array
    {
        return [];
    }

    public static function describe(): array
    {
        return [
            'key' => static::KEY,
            'name' => static::NAME,
            'chart' => static::CHART,
            'unit' => static::UNIT,
            'min_bars' => static::MIN_BARS,
            'summary' => 'Stocks trading close to their ' . static::SMA_PERIOD . '-day moving average (above or below it), with RSI(' . static::RSI_PERIOD . ') turning up and a heavy-volume day. Judged on the latest complete daily candle. Each symbol gets a score out of 100; near misses keep part of the points.',
            'rules' => [
                '35 points — The close is near the ' . static::SMA_PERIOD . '-day SMA: within ' . static::NEAR_FULL . '% of it (above or below) is full points, fading to nothing at ' . static::NEAR_ZERO . '% away.',
                '30 points — RSI(' . static::RSI_PERIOD . ') is moving up: 20 points for its rise over the last ' . static::RISE_DAYS . ' days (' . static::RISE_FULL . ' RSI points or more = full; halved if today is not above yesterday), and 10 points for RSI being above its own ' . static::RSI_AVG_PERIOD . '-day average (2 points under it keeps a little).',
                '35 points — High volume: today\'s volume against the average of the ' . static::VOL_LOOKBACK . ' days before. (1× the average = none, up to ' . static::VOLUME_X . '× = full.)',
            ],
            'columns' => [
                ['key' => 'ref_label', 'label' => 'Candle', 'title' => 'The daily candle judged (the latest complete one)', 'type' => 'text'],
                ['key' => 'dist_pct', 'label' => 'From SMA 50', 'title' => 'How far the close is from the 50-day SMA (+ above it, − below it)', 'type' => 'pct'],
                ['key' => 'sma', 'label' => 'SMA 50', 'title' => 'The 50-day simple moving average', 'type' => 'price'],
                ['key' => 'sma_slope_pct', 'label' => 'SMA 10d', 'title' => 'How much the 50-day SMA moved over the last 10 days (information only: rising or falling)', 'type' => 'pct'],
                ['key' => 'rsi', 'label' => 'RSI 14', 'title' => 'RSI (14) on the candle', 'type' => 'num'],
                ['key' => 'rsi_rise', 'label' => 'RSI ' . static::RISE_DAYS . 'd', 'title' => 'RSI points gained over the last ' . static::RISE_DAYS . ' days', 'type' => 'pct', 'unit' => ''],
                ['key' => 'volume_x', 'label' => 'Volume', 'title' => 'Today\'s volume against the average of the ' . static::VOL_LOOKBACK . ' days before', 'type' => 'x'],
            ],
            'params' => [],
        ];
    }

    /**
     * @param array $rows daily candles, oldest first: [['date','open','high','low','close','volume', ...], ...]
     * @return array{status: string, score: ?int, passed: int, total: int, checks: array, facts: ?array, message?: string}
     */
    public static function evaluate(array $rows, array $params = [], ?\DateTimeInterface $now = null): array
    {
        $rows = array_values($rows);
        // the candle of today is still forming until the close: judge the last complete one
        $now = $now ?? now();
        if ($rows && $rows[count($rows) - 1]['date'] === $now->format('Y-m-d') && $now->format('H:i') < static::MARKET_CLOSE) array_pop($rows);

        $n = count($rows);
        if ($n < static::MIN_BARS) {
            return ['status' => 'no_data', 'score' => null, 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Needs at least ' . static::MIN_BARS . ' days of candles (this has ' . $n . ')'];
        }
        $L = $n - 1;
        $f = fn ($v, $d = 2) => number_format((float) $v, $d, '.', '');

        $closes = array_map(fn ($r) => (float) $r['close'], $rows);
        $close = $closes[$L];
        $sma = array_sum(array_slice($closes, $L - static::SMA_PERIOD + 1, static::SMA_PERIOD)) / static::SMA_PERIOD;
        $smaBefore = array_sum(array_slice($closes, $L - 10 - static::SMA_PERIOD + 1, static::SMA_PERIOD)) / static::SMA_PERIOD;
        $dist = $sma > 0 ? ($close / $sma - 1) * 100 : 0.0;

        $rsi = Indicators::rsi($closes, static::RSI_PERIOD);
        $rsiAvg = Indicators::sma($rsi, static::RSI_AVG_PERIOD);
        $rNow = $rsi[$L]; $rPrev = $rsi[$L - 1]; $rThen = $rsi[$L - static::RISE_DAYS]; $rAvg = $rsiAvg[$L];
        if ($rNow === null || $rPrev === null || $rThen === null || $rAvg === null) {
            return ['status' => 'no_data', 'score' => null, 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Not enough candles to work out the RSI and its average'];
        }

        $pts = []; $details = [];

        // 1 — near the SMA (35)
        $ad = abs($dist);
        $near = $ad <= static::NEAR_FULL ? 1.0 : ($ad >= static::NEAR_ZERO ? 0.0 : (static::NEAR_ZERO - $ad) / (static::NEAR_ZERO - static::NEAR_FULL));
        $pts[1] = [35 * $near, 35];
        $details[1] = 'close ' . $f($close) . ' vs 50-day SMA ' . $f($sma) . ' (' . ($dist >= 0 ? '+' : '') . $f($dist, 1) . '%, ' . ($dist >= 0 ? 'above' : 'below') . '; full points within ' . static::NEAR_FULL . '%)';

        // 2 — RSI moving up (30): the rise over RISE_DAYS (20, halved when today is not above yesterday) + above its own average (10)
        $rise = $rNow - $rThen;
        $upToday = $rNow > $rPrev;
        $risePts = 20 * max(0.0, min(1.0, $rise / static::RISE_FULL)) * ($upToday ? 1.0 : 0.5);
        $gap = $rAvg - $rNow;   // > 0 = RSI is under its average
        $avgPts = $gap <= 0 ? 10.0 : 10 * max(0.0, 1 - $gap / 2);
        $pts[2] = [$risePts + $avgPts, 30];
        $details[2] = 'RSI ' . $f($rNow, 1) . ' vs ' . $f($rThen, 1) . ' ' . static::RISE_DAYS . ' days ago (' . ($rise >= 0 ? '+' : '') . $f($rise, 1) . '), ' . ($upToday ? 'up' : 'not up') . ' on yesterday (' . $f($rPrev, 1) . '); its ' . static::RSI_AVG_PERIOD . '-day average is ' . $f($rAvg, 1) . ($gap <= 0 ? ' (RSI above it)' : ' (RSI under it)');

        // 3 — volume against the 20 days before (35)
        $vols = [];
        for ($i = max(0, $L - static::VOL_LOOKBACK); $i < $L; $i++) $vols[] = (float) $rows[$i]['volume'];
        $avg = $vols ? array_sum($vols) / count($vols) : 0;
        $volX = $avg > 0 ? (float) $rows[$L]['volume'] / $avg : 0.0;
        $pts[3] = [35 * ($volX >= static::VOLUME_X ? 1.0 : max(0.0, ($volX - 1.0) / (static::VOLUME_X - 1.0))), 35];
        $details[3] = $f($volX, 1) . '× the ' . count($vols) . '-day average (needs ≥ ' . $f(static::VOLUME_X, 1) . '×)';

        $names = [1 => 'Close near the ' . static::SMA_PERIOD . '-day SMA', 2 => 'RSI(' . static::RSI_PERIOD . ') moving up', 3 => 'High volume'];
        $checks = []; $total = 0.0;
        foreach ($pts as $i => [$got, $max]) {
            $total += $got;
            $checks[] = ['rule' => $names[$i], 'ok' => $got >= $max - 1e-9, 'points' => round($got, 1), 'max' => $max, 'detail' => $details[$i]];
        }
        $score = (int) round($total);

        return [
            'status' => 'scored', 'score' => $score, 'passed' => count(array_filter(array_column($checks, 'ok'))), 'total' => count($checks), 'checks' => $checks,
            'facts' => [
                'score'         => $score,
                'ref_label'     => date(static::DATE_FMT, strtotime($rows[$L]['date'])),
                'bars_ago'      => 0,
                'dist_pct'      => round($dist, 1),
                'sma'           => round($sma, 2),
                'sma_slope_pct' => $smaBefore > 0 ? round(($sma / $smaBefore - 1) * 100, 1) : null,
                'rsi'           => round($rNow, 1),
                'rsi_rise'      => round($rise, 1),
                'volume_x'      => round($volX, 1),
                'last_close'    => round($close, 2),
                'last_label'    => date(static::DATE_FMT, strtotime($rows[$L]['date'])),
            ],
        ];
    }
}
