<?php

namespace App\Services\StockScanner;

/**
 * Monthly chart pattern: a big breakout month on heavy volume, then a shallow pullback that holds.
 *
 *   B = the breakout month, 1 to 4 months before the latest (still forming) month L.
 *
 *   1  B is a big green candle: body at least 10% of its open, and the largest green body of the 12 months before it
 *   2  B closed above the highest high of the 6 months before it (a real breakout, not a bounce inside a range)
 *   3  B's volume is at least 1.5x the average of the 12 months before it
 *   4  B closed above its 20-month SMA and its 5-month SMA
 *   5  since B, no month closed below its own 20-month SMA or below the middle of B's body (the pullback holds)
 *   6  the latest month closes below B's close (it is a pullback, not another leg up)
 *   7  5-month SMA is above the 20-month SMA now
 *   8  the latest month's low reached the 5-month SMA (within 3%) — price has come back to it
 *
 * Fixed on purpose: no settings. Pure — takes the monthly candles, returns the verdict and the numbers behind it.
 */
class MonthlyBreakoutPullback
{
    const KEY  = 'monthly_breakout_pullback';
    const NAME = 'Monthly breakout & pullback';

    const MIN_BODY_PCT = 10;    // rule 1
    const BODY_LOOKBACK = 12;   // rule 1
    const HIGH_LOOKBACK = 6;    // rule 2
    const VOL_LOOKBACK = 12;    // rule 3
    const VOL_MULT = 1.5;       // rule 3
    const MAX_AGE = 4;          // months between B and the latest month
    const TOUCH_PCT = 3;        // rule 8
    const MIN_MONTHS = 22;      // 20-month SMA must exist on both B and the latest month

    public static function describe(): array
    {
        return [
            'key' => self::KEY,
            'name' => self::NAME,
            'chart' => 'Monthly',
            'summary' => 'A big breakout month on heavy volume, then a shallow pullback to the 5-month average that holds above the 20-month average.',
            'rules' => [
                'A breakout month 1–4 months ago: a green candle with a body of at least ' . self::MIN_BODY_PCT . '%, the biggest green body of the 12 months before it',
                'It closed above the highest high of the previous ' . self::HIGH_LOOKBACK . ' months',
                'Its volume was at least ' . self::VOL_MULT . '× the average of the previous ' . self::VOL_LOOKBACK . ' months',
                'It closed above the 20-month and the 5-month average',
                'Since then no month closed below the 20-month average or below the middle of the breakout candle',
                'The latest month closes below the breakout close (a pullback, not a new high)',
                'The 5-month average is above the 20-month average',
                'The latest month\'s low has come back to the 5-month average (within ' . self::TOUCH_PCT . '%)',
            ],
        ];
    }

    /**
     * @param array $rows monthly candles, oldest first: [['date', 'open', 'high', 'low', 'close', 'volume', 'sma20'], ...]; the last one may be the month in progress
     * @return array{status: string, passed: int, total: int, checks: array, facts: ?array, message?: string}
     */
    public static function evaluate(array $rows): array
    {
        $rows = array_values($rows);
        $n = count($rows);
        $L = $n - 1;
        if ($n < self::MIN_MONTHS || ($rows[$L]['sma20'] ?? null) === null) {
            return ['status' => 'no_data', 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Needs at least ' . self::MIN_MONTHS . ' months of candles (this has ' . $n . ')'];
        }

        $sma5 = self::sma(array_column($rows, 'close'), 5);
        $best = null;

        for ($age = 1; $age <= self::MAX_AGE; $age++) {
            $b = $L - $age;
            if ($b < 6 || ($rows[$b]['sma20'] ?? null) === null) continue;
            $r = self::check($rows, $b, $L, $sma5);
            if ($best === null || $r['passed'] > $best['passed']) $best = $r;   // ties keep the most recent breakout candidate
            if ($r['passed'] === $r['total']) { $best = $r; break; }
        }
        if ($best === null) {
            return ['status' => 'no_data', 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => 'Not enough monthly history to find a breakout'];
        }

        $best['status'] = $best['passed'] === $best['total'] ? 'match' : 'no_match';
        return $best;
    }

    private static function check(array $rows, int $b, int $L, array $sma5): array
    {
        $B = $rows[$b]; $last = $rows[$L];
        $open = (float) $B['open']; $close = (float) $B['close'];
        $body = $close - $open;
        $bodyPct = $open > 0 ? $body / $open * 100 : 0;

        // 1 — big green candle, the biggest green body of the 12 months before it
        $maxPrior = 0.0;
        for ($i = max(0, $b - self::BODY_LOOKBACK); $i < $b; $i++) $maxPrior = max($maxPrior, (float) $rows[$i]['close'] - (float) $rows[$i]['open']);
        $c1 = $body > 0 && $bodyPct >= self::MIN_BODY_PCT && $body >= $maxPrior;

        // 2 — closed above the highest high of the previous 6 months
        $hh = 0.0;
        for ($i = max(0, $b - self::HIGH_LOOKBACK); $i < $b; $i++) $hh = max($hh, (float) $rows[$i]['high']);
        $c2 = $close > $hh;

        // 3 — volume at least 1.5x the previous 12-month average
        $vols = [];
        for ($i = max(0, $b - self::VOL_LOOKBACK); $i < $b; $i++) $vols[] = (float) $rows[$i]['volume'];
        $avgVol = $vols ? array_sum($vols) / count($vols) : 0;
        $volX = $avgVol > 0 ? (float) $B['volume'] / $avgVol : 0;
        $c3 = $volX >= self::VOL_MULT;

        // 4 — closed above its 20-month and 5-month SMA
        $c4 = $close > (float) $B['sma20'] && $sma5[$b] !== null && $close > $sma5[$b];

        // 5 — since the breakout: no close under that month's SMA20, none under the middle of the breakout body
        $mid = ($open + $close) / 2;
        $holds = true; $minClose = null; $why = '';
        for ($i = $b + 1; $i <= $L; $i++) {
            $c = (float) $rows[$i]['close'];
            $minClose = $minClose === null ? $c : min($minClose, $c);
            if ($rows[$i]['sma20'] !== null && $c < (float) $rows[$i]['sma20']) { $holds = false; $why = 'closed under the 20-month SMA in ' . self::month($rows[$i]['date']); break; }
            if ($c < $mid) { $holds = false; $why = 'closed under the middle of the breakout candle in ' . self::month($rows[$i]['date']); break; }
        }
        $c5 = $holds;

        // 6 — the latest month is below the breakout close
        $lastClose = (float) $last['close'];
        $c6 = $lastClose < $close;

        // 7 — SMA5 above SMA20 now
        $s5 = $sma5[$L]; $s20 = $last['sma20'] !== null ? (float) $last['sma20'] : null;
        $c7 = $s5 !== null && $s20 !== null && $s5 > $s20;

        // 8 — the latest low has reached the 5-month SMA
        $lastLow = (float) $last['low'];
        $c8 = $s5 !== null && $lastLow <= $s5 * (1 + self::TOUCH_PCT / 100);

        $f = fn ($v, $d = 2) => number_format((float) $v, $d, '.', '');
        $checks = [
            ['rule' => 'Big green breakout candle',        'ok' => $c1, 'detail' => 'body ' . $f($bodyPct, 1) . '% (needs ≥ ' . self::MIN_BODY_PCT . '%)' . ($body > 0 && $body < $maxPrior ? ', but a bigger green month came before it' : '')],
            ['rule' => 'Closed above the 6-month high',    'ok' => $c2, 'detail' => 'close ' . $f($close) . ' vs the previous high ' . $f($hh)],
            ['rule' => 'Heavy volume',                     'ok' => $c3, 'detail' => $f($volX, 1) . '× the 12-month average (needs ≥ ' . self::VOL_MULT . '×)'],
            ['rule' => 'Closed above 20 and 5-month SMA',  'ok' => $c4, 'detail' => 'close ' . $f($close) . ', SMA20 ' . $f($B['sma20']) . ', SMA5 ' . ($sma5[$b] !== null ? $f($sma5[$b]) : '—')],
            ['rule' => 'The pullback holds',               'ok' => $c5, 'detail' => $c5 ? 'lowest close since ' . $f($minClose) . ', middle of the breakout candle ' . $f($mid) : $why],
            ['rule' => 'Latest month is below the breakout close', 'ok' => $c6, 'detail' => 'close ' . $f($lastClose) . ' vs breakout close ' . $f($close)],
            ['rule' => '5-month SMA above 20-month SMA',   'ok' => $c7, 'detail' => 'SMA5 ' . ($s5 !== null ? $f($s5) : '—') . ', SMA20 ' . ($s20 !== null ? $f($s20) : '—')],
            ['rule' => 'Latest low reached the 5-month SMA', 'ok' => $c8, 'detail' => 'low ' . $f($lastLow) . ' vs SMA5 ' . ($s5 !== null ? $f($s5) : '—') . ' (within ' . self::TOUCH_PCT . '%)'],
        ];

        $passed = count(array_filter(array_column($checks, 'ok')));
        $rsi = self::rsi(array_column($rows, 'close'), 9);

        return [
            'passed' => $passed, 'total' => count($checks), 'checks' => $checks,
            'facts' => [
                'breakout_month' => self::month($B['date']),
                'months_ago'     => $L - $b,
                'body_pct'       => round($bodyPct, 1),
                'volume_x'       => round($volX, 1),
                'breakout_close' => round($close, 2),
                'last_close'     => round($lastClose, 2),
                'pullback_pct'   => $close > 0 ? round(($close - $lastClose) / $close * 100, 1) : null,   // % below the breakout close
                'retrace_pct'    => $body > 0 ? round(($close - $lastClose) / $body * 100, 1) : null,     // % of the breakout body given back
                'sma5'           => $s5 !== null ? round($s5, 2) : null,
                'sma20'          => $s20 !== null ? round($s20, 2) : null,
                'rsi9'           => $rsi !== null ? round($rsi, 1) : null,
                'last_month'     => self::month($last['date']),
            ],
        ];
    }

    private static function month(string $date): string
    {
        return date('M Y', strtotime($date));
    }

    /** Simple moving average of $period closes at every index (null until there are enough). */
    private static function sma(array $v, int $period): array
    {
        $out = []; $sum = 0.0;
        foreach ($v as $i => $x) {
            $sum += (float) $x;
            if ($i >= $period) $sum -= (float) $v[$i - $period];
            $out[$i] = $i >= $period - 1 ? $sum / $period : null;
        }
        return $out;
    }

    /** Wilder's RSI at the last close (null with too little history). */
    private static function rsi(array $closes, int $period): ?float
    {
        $n = count($closes);
        if ($n <= $period) return null;
        $gain = 0.0; $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $d = (float) $closes[$i] - (float) $closes[$i - 1];
            $d >= 0 ? $gain += $d : $loss -= $d;
        }
        $gain /= $period; $loss /= $period;
        for ($i = $period + 1; $i < $n; $i++) {
            $d = (float) $closes[$i] - (float) $closes[$i - 1];
            $gain = ($gain * ($period - 1) + max($d, 0)) / $period;
            $loss = ($loss * ($period - 1) + max(-$d, 0)) / $period;
        }
        return $loss == 0 ? 100.0 : 100 - 100 / (1 + $gain / $loss);
    }
}
