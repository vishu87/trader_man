<?php

namespace App\Services\StockScanner;

/**
 * Monthly chart: the first close above the upper Bollinger band after a long quiet spell, on the highest RSI and heavy volume.
 * (The user's hand-marked RBA chart.)
 *
 *   R = the reference candle: the current month, or up to 5 months back.
 *
 *   1  R is green and closed ABOVE its upper Bollinger band (20, 2)
 *   2  for the 8 months before R, no candle closed above its upper band (they were all under it)
 *   3  R's RSI(9) is the highest of all the earlier months AND of the months after it (if any) — within the RSI flexibility
 *   4  RSI(9)'s 3-month average crossed above its 21-month average in the 6 months up to R, and is above it on R
 *   5  R's volume is at least [volume multiple] x the average of the 20 months before it
 *
 * Fixed rules, except two the user wanted some give in: the RSI flexibility (points) and the volume multiple.
 * Pure — takes the monthly candles, returns the verdict and the numbers behind it.
 */
class MonthlyBollingerBreakout
{
    const KEY  = 'monthly_bollinger_breakout';
    const NAME = 'Monthly Bollinger breakout';

    const MAX_AGE = 5;           // R is this many months back at most (0 = the current month)
    const QUIET_MONTHS = 8;      // rule 2
    const CROSS_WINDOW = 6;      // rule 4
    const VOL_LOOKBACK = 20;     // rule 5
    const RSI_PERIOD = 9;
    const MIN_MONTHS = 30;       // RSI(9) + its 21-month average must exist on R (9 + 21 months)

    const DEFAULT_VOLUME_X = 1.5;
    const DEFAULT_RSI_FLEX = 0;

    /** The two settings, cleaned: out-of-range or missing values fall back to the defaults. */
    public static function normalize(array $in): array
    {
        $vol = isset($in['volume_x']) && is_numeric($in['volume_x']) ? (float) $in['volume_x'] : self::DEFAULT_VOLUME_X;
        $flex = isset($in['rsi_flex']) && is_numeric($in['rsi_flex']) ? (float) $in['rsi_flex'] : self::DEFAULT_RSI_FLEX;
        return ['volume_x' => min(max($vol, 0.5), 20.0), 'rsi_flex' => min(max($flex, 0.0), 30.0)];
    }

    public static function describe(): array
    {
        return [
            'key' => self::KEY,
            'name' => self::NAME,
            'chart' => 'Monthly',
            'min_months' => self::MIN_MONTHS,
            'summary' => 'The first monthly close above the upper Bollinger band after months spent under it — on the highest RSI(9) and heavy volume. That candle becomes the reference candle.',
            'rules' => [
                'The reference candle is green and closed above its upper Bollinger band (20, 2). It is the current month, or up to ' . self::MAX_AGE . ' months back',
                'For the ' . self::QUIET_MONTHS . ' months before it, no candle closed above the upper band',
                'Its RSI(9) is the highest of all the earlier months, and of the months after it (if any)',
                'RSI(9)\'s 3-month average crossed above its 21-month average within the ' . self::CROSS_WINDOW . ' months up to it, and is above it on that candle',
                'Its volume is above the average of the previous ' . self::VOL_LOOKBACK . ' months by the volume multiple',
            ],
            'params' => [
                ['key' => 'volume_x', 'label' => 'Volume at least', 'suffix' => '× the 20-month average', 'default' => self::DEFAULT_VOLUME_X, 'min' => 0.5, 'max' => 20, 'step' => 0.1],
                ['key' => 'rsi_flex', 'label' => 'RSI may be below the highest by up to', 'suffix' => 'points', 'default' => self::DEFAULT_RSI_FLEX, 'min' => 0, 'max' => 30, 'step' => 0.5],
            ],
        ];
    }

    /**
     * @param array $rows monthly candles, oldest first: [['date','open','high','low','close','volume','bb_high'], ...]; the last may be the month in progress
     * @return array{status: string, passed: int, total: int, checks: array, facts: ?array, message?: string}
     */
    public static function evaluate(array $rows, array $params = []): array
    {
        $p = self::normalize($params);
        $rows = array_values($rows);
        $n = count($rows);
        $L = $n - 1;
        if ($n < self::MIN_MONTHS) {
            return ['status' => 'no_data', 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Needs at least ' . self::MIN_MONTHS . ' months of candles (this has ' . $n . ')'];
        }

        $rsi  = self::rsiSeries(array_column($rows, 'close'), self::RSI_PERIOD);
        $ma3  = self::sma($rsi, 3);
        $ma21 = self::sma($rsi, 21);

        $best = null;
        for ($age = 0; $age <= self::MAX_AGE; $age++) {
            $r = $L - $age;
            if ($r < self::QUIET_MONTHS || $ma21[$r] === null || ($rows[$r]['bb_high'] ?? null) === null) continue;
            $res = self::check($rows, $r, $L, $rsi, $ma3, $ma21, $p);
            if ($best === null || $res['passed'] > $best['passed']) $best = $res;   // ties keep the most recent candidate
            if ($res['passed'] === $res['total']) { $best = $res; break; }
        }
        if ($best === null) {
            return ['status' => 'no_data', 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Not enough monthly history (with Bollinger bands and RSI) to judge the last ' . (self::MAX_AGE + 1) . ' months'];
        }

        $best['status'] = $best['passed'] === $best['total'] ? 'match' : 'no_match';
        return $best;
    }

    private static function check(array $rows, int $r, int $L, array $rsi, array $ma3, array $ma21, array $p): array
    {
        $R = $rows[$r];
        $open = (float) $R['open']; $close = (float) $R['close']; $band = (float) $R['bb_high'];
        $f = fn ($v, $d = 2) => number_format((float) $v, $d, '.', '');

        // 1 — green, and closed above its upper band
        $c1 = $close > $open && $close > $band;
        $aboveBand = $band > 0 ? ($close / $band - 1) * 100 : 0;
        $d1 = ($close > $open ? 'green' : 'not green') . ', close ' . $f($close) . ' vs upper band ' . $f($band) . ' (' . ($aboveBand >= 0 ? '+' : '') . $f($aboveBand, 1) . '%)';

        // 2 — the 8 months before: none closed above its upper band
        $over = null;
        for ($i = $r - self::QUIET_MONTHS; $i < $r; $i++) {
            if ($rows[$i]['bb_high'] !== null && (float) $rows[$i]['close'] > (float) $rows[$i]['bb_high']) $over = $rows[$i]['date'];
        }
        $c2 = $over === null;
        $d2 = $c2 ? 'all ' . self::QUIET_MONTHS . ' months before closed under the band' : 'closed above the band in ' . self::month($over);

        // 3 — highest RSI(9) of the earlier months and of the months after it
        $others = [];
        foreach ($rsi as $i => $v) if ($v !== null && $i !== $r) $others[$i] = $v;
        $maxOther = $others ? max($others) : null;
        $maxAt = $maxOther !== null ? array_search($maxOther, $others, true) : null;
        $rsiR = $rsi[$r];
        $c3 = $rsiR !== null && ($maxOther === null || $rsiR >= $maxOther - $p['rsi_flex']);
        $d3 = 'RSI ' . $f($rsiR, 1) . ($maxOther !== null ? ' vs the highest of the other months ' . $f($maxOther, 1) . ' (' . self::month($rows[$maxAt]['date']) . ')' : '') . ($p['rsi_flex'] > 0 ? ', flexibility ' . $f($p['rsi_flex'], 1) : '');

        // 4 — RSI 3-month average crossed above the 21-month average within the 6 months up to R, and is above it on R
        $cross = null;
        for ($k = max(1, $r - self::CROSS_WINDOW); $k <= $r; $k++) {
            if ($ma3[$k] !== null && $ma21[$k] !== null && $ma3[$k - 1] !== null && $ma21[$k - 1] !== null && $ma3[$k - 1] <= $ma21[$k - 1] && $ma3[$k] > $ma21[$k]) $cross = $k;
        }
        $above = $ma3[$r] !== null && $ma21[$r] !== null && $ma3[$r] > $ma21[$r];
        $c4 = $cross !== null && $above;
        $d4 = ($cross !== null ? 'crossed above in ' . self::month($rows[$cross]['date']) : 'no cross above in the last ' . self::CROSS_WINDOW . ' months') . '; on the reference candle average(3) ' . ($ma3[$r] !== null ? $f($ma3[$r], 1) : '—') . ' vs average(21) ' . ($ma21[$r] !== null ? $f($ma21[$r], 1) : '—');

        // 5 — volume against the 20 months before
        $vols = [];
        for ($i = max(0, $r - self::VOL_LOOKBACK); $i < $r; $i++) $vols[] = (float) $rows[$i]['volume'];
        $avg = $vols ? array_sum($vols) / count($vols) : 0;
        $volX = $avg > 0 ? (float) $R['volume'] / $avg : 0;
        $c5 = $volX >= $p['volume_x'];
        $d5 = $f($volX, 1) . '× the ' . count($vols) . '-month average (needs ≥ ' . $f($p['volume_x'], 1) . '×)';

        $checks = [
            ['rule' => 'Green candle closed above the upper Bollinger band', 'ok' => $c1, 'detail' => $d1],
            ['rule' => 'The ' . self::QUIET_MONTHS . ' months before were all under the band', 'ok' => $c2, 'detail' => $d2],
            ['rule' => 'Highest RSI(9) of the earlier and later months', 'ok' => $c3, 'detail' => $d3],
            ['rule' => 'RSI average(3) crossed above average(21)', 'ok' => $c4, 'detail' => $d4],
            ['rule' => 'High volume', 'ok' => $c5, 'detail' => $d5],
        ];

        return [
            'passed' => count(array_filter(array_column($checks, 'ok'))), 'total' => count($checks), 'checks' => $checks,
            'facts' => [
                'ref_month'      => self::month($R['date']),
                'months_ago'     => $L - $r,
                'body_pct'       => $open > 0 ? round(($close / $open - 1) * 100, 1) : null,
                'above_band_pct' => round($aboveBand, 1),
                'rsi_ref'        => $rsiR !== null ? round($rsiR, 1) : null,
                'rsi_now'        => $rsi[$L] !== null ? round($rsi[$L], 1) : null,
                'volume_x'       => round($volX, 1),
                'cross_month'    => $cross !== null ? self::month($rows[$cross]['date']) : null,
                'rsi_avg_above_now' => $ma3[$L] !== null && $ma21[$L] !== null ? $ma3[$L] > $ma21[$L] : null,   // information only
                'ref_close'      => round($close, 2),
                'last_close'     => round((float) $rows[$L]['close'], 2),
                'last_month'     => self::month($rows[$L]['date']),
            ],
        ];
    }

    private static function month(string $date): string
    {
        return date('M Y', strtotime($date));
    }

    /** Simple moving average over a series that may start with nulls: null until $period real values are in a row. */
    private static function sma(array $v, int $period): array
    {
        $out = [];
        foreach ($v as $i => $x) {
            $out[$i] = null;
            if ($i < $period - 1) continue;
            $sum = 0.0; $ok = true;
            for ($j = $i - $period + 1; $j <= $i; $j++) { if ($v[$j] === null) { $ok = false; break; } $sum += $v[$j]; }
            if ($ok) $out[$i] = $sum / $period;
        }
        return $out;
    }

    /** Wilder's RSI at every close (null for the first $period). */
    private static function rsiSeries(array $closes, int $period): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= $period) return $out;
        $gain = 0.0; $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $d = (float) $closes[$i] - (float) $closes[$i - 1];
            $d >= 0 ? $gain += $d : $loss -= $d;
        }
        $gain /= $period; $loss /= $period;
        $out[$period] = $loss == 0 ? 100.0 : 100 - 100 / (1 + $gain / $loss);
        for ($i = $period + 1; $i < $n; $i++) {
            $d = (float) $closes[$i] - (float) $closes[$i - 1];
            $gain = ($gain * ($period - 1) + max($d, 0)) / $period;
            $loss = ($loss * ($period - 1) + max(-$d, 0)) / $period;
            $out[$i] = $loss == 0 ? 100.0 : 100 - 100 / (1 + $gain / $loss);
        }
        return $out;
    }
}
