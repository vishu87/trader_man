<?php

namespace App\Services;

/**
 * Indicators computed from closes, shared by the chart API and the Stock Scanner. Pure.
 */
class Indicators
{
    /** Wilder's RSI at every close (null for the first $period closes). */
    public static function rsi(array $closes, int $period = 9): array
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

    /** Simple moving average over a series that may start with nulls: null until $period real values are in a row. */
    public static function sma(array $v, int $period): array
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
}
