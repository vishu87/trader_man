<?php

namespace App\Services\StockScanner;

use App\Services\Indicators;

/**
 * Monthly chart: the first close above the upper Bollinger band after a long quiet spell, on the highest RSI and heavy volume.
 * (The user's hand-marked RBA chart.)
 *
 *   R = the reference candle: the current month, or up to 5 months back. The best-scoring month is the one judged.
 *
 *   1  (25 pts) R is green and closed ABOVE its upper Bollinger band (20, 2)
 *   2  (20 pts) for the 8 months before R, no candle closed above its upper band (they were all under it)
 *   3  (20 pts) R's RSI(9) is the highest of all the earlier months AND of the months after it (if any) — within the RSI flexibility
 *   4  (20 pts) RSI(9)'s 3-month average crossed above its 21-month average in the 6 months up to R, and is above it on R
 *   5  (15 pts) R's volume is at least [volume multiple] x the average of the 20 months before it
 *
 * Not pass / fail: every rule earns partial credit for a near miss (see each rule's detail), and the points add up to a 0-100 SCORE.
 * Every rule fully met = 100. The page shows the symbols at or above a minimum score (80 by default).
 * The rules are fixed (no settings). Pure — takes the monthly candles, returns the score and the numbers behind it.
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

    /** The rules are FIXED (this is a first-pass scan, nothing is ordered from it): whatever is passed in, the defaults are used. */
    public static function normalize(array $in = []): array
    {
        return ['volume_x' => self::DEFAULT_VOLUME_X, 'rsi_flex' => self::DEFAULT_RSI_FLEX];
    }

    public static function describe(): array
    {
        return [
            'key' => self::KEY,
            'name' => self::NAME,
            'chart' => 'Monthly',
            'min_months' => self::MIN_MONTHS,
            'summary' => 'The first monthly close above the upper Bollinger band after months spent under it — on the highest RSI(9) and heavy volume. That candle becomes the reference candle. Each symbol gets a score out of 100; near misses keep part of the points.',
            'rules' => [
                '25 points — The reference candle is green and closed above its upper Bollinger band (20, 2). It is the current month, or up to ' . self::MAX_AGE . ' months back. (Above the band: most of the points; green: the rest. Closing up to 3% under the band keeps a little.)',
                '20 points — For the ' . self::QUIET_MONTHS . ' months before it, no candle closed above the upper band. (Each month that did costs ' . (20 / self::QUIET_MONTHS) . ' points.)',
                '20 points — Its RSI(9) is the highest of all the earlier months, and of the months after it (if any). (Every RSI point below the highest costs 2 points, so 10 points short = none.)',
                '20 points — RSI(9)\'s 3-month average crossed above its 21-month average within the ' . self::CROSS_WINDOW . ' months up to it and is above it on that candle. (Above it but crossed earlier: 12 points; crossed in the window but now below: 5.)',
                '15 points — Its volume is at least ' . self::DEFAULT_VOLUME_X . '× the average of the previous ' . self::VOL_LOOKBACK . ' months. (Scales from 1× the average = none up to ' . self::DEFAULT_VOLUME_X . '× = full.)',
            ],
            'params' => [],   // fixed rules: nothing to set
        ];
    }

    /**
     * @param array $rows monthly candles, oldest first: [['date','open','high','low','close','volume','bb_high'], ...]; the last may be the month in progress
     * @return array{status: string, score: ?int, passed: int, total: int, checks: array, facts: ?array, message?: string}  status 'scored' (score 0-100) or 'no_data'
     */
    public static function evaluate(array $rows, array $params = []): array
    {
        $p = self::normalize($params);
        $rows = array_values($rows);
        $n = count($rows);
        $L = $n - 1;
        if ($n < self::MIN_MONTHS) {
            return ['status' => 'no_data', 'score' => null, 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Needs at least ' . self::MIN_MONTHS . ' months of candles (this has ' . $n . ')'];
        }

        $rsi  = Indicators::rsi(array_column($rows, 'close'), self::RSI_PERIOD);
        $ma3  = Indicators::sma($rsi, 3);
        $ma21 = Indicators::sma($rsi, 21);

        $best = null;
        for ($age = 0; $age <= self::MAX_AGE; $age++) {
            $r = $L - $age;
            if ($r < self::QUIET_MONTHS || $ma21[$r] === null || ($rows[$r]['bb_high'] ?? null) === null) continue;
            $res = self::check($rows, $r, $L, $rsi, $ma3, $ma21, $p);
            if ($best === null || $res['score'] > $best['score']) $best = $res;   // ties keep the most recent candidate
        }
        if ($best === null) {
            return ['status' => 'no_data', 'score' => null, 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Not enough monthly history (with Bollinger bands and RSI) to judge the last ' . (self::MAX_AGE + 1) . ' months'];
        }

        $best['status'] = 'scored';
        return $best;
    }

    private static function check(array $rows, int $r, int $L, array $rsi, array $ma3, array $ma21, array $p): array
    {
        $R = $rows[$r];
        $open = (float) $R['open']; $close = (float) $R['close']; $band = (float) $R['bb_high'];
        $f = fn ($v, $d = 2) => number_format((float) $v, $d, '.', '');

        $pts = [];   // rule => [points earned, points possible]

        // 1 — green, and closed above its upper band (25): above the band 70 %, green 30 %; closing up to 3 % under the band keeps part of the band share
        $green = $close > $open;
        $aboveBand = $band > 0 ? ($close / $band - 1) * 100 : 0;
        if ($close > $band) $bandCredit = 1.0;
        else { $gap = -$aboveBand; $bandCredit = $gap <= 3 ? 0.5 * (1 - $gap / 3) : 0.0; }
        $pts[1] = [25 * (0.7 * $bandCredit + 0.3 * ($green ? 1 : 0)), 25];
        $d1 = ($green ? 'green' : 'not green') . ', close ' . $f($close) . ' vs upper band ' . $f($band) . ' (' . ($aboveBand >= 0 ? '+' : '') . $f($aboveBand, 1) . '%)';

        // 2 — the 8 months before: none closed above its upper band (20): each month that did costs 2.5
        $overMonths = [];
        for ($i = $r - self::QUIET_MONTHS; $i < $r; $i++) {
            if ($rows[$i]['bb_high'] !== null && (float) $rows[$i]['close'] > (float) $rows[$i]['bb_high']) $overMonths[] = self::month($rows[$i]['date']);
        }
        $pts[2] = [20 * (self::QUIET_MONTHS - count($overMonths)) / self::QUIET_MONTHS, 20];
        $d2 = $overMonths ? count($overMonths) . ' of the ' . self::QUIET_MONTHS . ' months closed above the band (' . implode(', ', $overMonths) . ')' : 'all ' . self::QUIET_MONTHS . ' months before closed under the band';

        // 3 — highest RSI(9) of the earlier and later months (20): within the flexibility = full, then 2 points lost per RSI point short, none at 10 short
        $others = [];
        foreach ($rsi as $i => $v) if ($v !== null && $i !== $r) $others[$i] = $v;
        $maxOther = $others ? max($others) : null;
        $maxAt = $maxOther !== null ? array_search($maxOther, $others, true) : null;
        $rsiR = $rsi[$r];
        $short = ($rsiR === null) ? 10.0 : ($maxOther === null ? 0.0 : max(0.0, $maxOther - $p['rsi_flex'] - $rsiR));
        $pts[3] = [20 * max(0.0, 1 - $short / 10), 20];
        $d3 = 'RSI ' . $f($rsiR, 1) . ($maxOther !== null ? ' vs the highest of the other months ' . $f($maxOther, 1) . ' (' . self::month($rows[$maxAt]['date']) . ')' : '') . ($p['rsi_flex'] > 0 ? ', flexibility ' . $f($p['rsi_flex'], 1) : '');

        // 4 — RSI 3-month average crossed above the 21-month average within the 6 months up to R, and is above it on R (20)
        $cross = null;
        for ($k = max(1, $r - self::CROSS_WINDOW); $k <= $r; $k++) {
            if ($ma3[$k] !== null && $ma21[$k] !== null && $ma3[$k - 1] !== null && $ma21[$k - 1] !== null && $ma3[$k - 1] <= $ma21[$k - 1] && $ma3[$k] > $ma21[$k]) $cross = $k;
        }
        $above = $ma3[$r] !== null && $ma21[$r] !== null && $ma3[$r] > $ma21[$r];
        $pts[4] = [20 * ($above ? ($cross !== null ? 1.0 : 0.6) : ($cross !== null ? 0.25 : 0.0)), 20];
        $d4 = ($cross !== null ? 'crossed above in ' . self::month($rows[$cross]['date']) : 'no cross above in the last ' . self::CROSS_WINDOW . ' months') . '; on the reference candle average(3) ' . ($ma3[$r] !== null ? $f($ma3[$r], 1) : '—') . ' vs average(21) ' . ($ma21[$r] !== null ? $f($ma21[$r], 1) : '—');

        // 5 — volume against the 20 months before (15): 1x the average = none, the volume multiple = full
        $vols = [];
        for ($i = max(0, $r - self::VOL_LOOKBACK); $i < $r; $i++) $vols[] = (float) $rows[$i]['volume'];
        $avg = $vols ? array_sum($vols) / count($vols) : 0;
        $volX = $avg > 0 ? (float) $R['volume'] / $avg : 0;
        $need = $p['volume_x'];
        $lo = $need > 1 ? 1.0 : 0.0;
        $pts[5] = [15 * ($volX >= $need ? 1.0 : max(0.0, ($volX - $lo) / ($need - $lo))), 15];
        $d5 = $f($volX, 1) . '× the ' . count($vols) . '-month average (needs ≥ ' . $f($need, 1) . '×)';

        $names = [1 => 'Green candle closed above the upper Bollinger band', 2 => 'The ' . self::QUIET_MONTHS . ' months before were all under the band',
                  3 => 'Highest RSI(9) of the earlier and later months', 4 => 'RSI average(3) crossed above average(21)', 5 => 'High volume'];
        $details = [1 => $d1, 2 => $d2, 3 => $d3, 4 => $d4, 5 => $d5];
        $checks = []; $total = 0.0;
        foreach ($pts as $i => [$got, $max]) {
            $total += $got;
            $checks[] = ['rule' => $names[$i], 'ok' => $got >= $max - 1e-9, 'points' => round($got, 1), 'max' => $max, 'detail' => $details[$i]];
        }
        $score = (int) round($total);

        return [
            'score' => $score, 'passed' => count(array_filter(array_column($checks, 'ok'))), 'total' => count($checks), 'checks' => $checks,
            'facts' => [
                'score'          => $score,
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
}
