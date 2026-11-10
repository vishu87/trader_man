<?php

namespace App\Services\Scanner;

/**
 * Does the scanner's file know the future?
 *
 * A signal stamped 9:15 can only be traded if the scanner could really have known it at 9:20. A common trap: a scanner's history
 * is built with whole-day values (the day's final close / high), so a stock appears from the FIRST candle of a day on which it went
 * on to rally. The tell-tale: at the first signal the stock has barely moved, yet EVERY flagged stock-day finishes well up.
 *
 * This measures that, from the first signal of each stock-day, against the previous close.
 */
class LeakCheck
{
    public static function run(array $signals, array $candles, array $daily): array
    {
        $first = []; $firstPrice = [];   // symbol|date → earliest signal_at (and the price the alert carried, if any)
        foreach ($signals as $s) {
            $k = $s['symbol'] . '|' . substr($s['signal_at'], 0, 10);
            if (!isset($first[$k]) || $s['signal_at'] < $first[$k]) { $first[$k] = $s['signal_at']; $firstPrice[$k] = $s['trigger_price'] ?? null; }
        }

        $rows = [];
        foreach ($first as $k => $at) {
            [$symbol, $date] = explode('|', $k);
            if (empty($candles[$symbol]) || empty($daily[$symbol])) continue;

            $prev = null;
            foreach ($daily[$symbol] as $d) { if ($d[0] < $date) $prev = $d[Backtester::C]; else break; }
            if (!$prev) continue;

            $day = array_values(array_filter($candles[$symbol], fn ($c) => substr($c[Backtester::T], 0, 10) === $date));
            if (!$day) continue;
            $i = null;
            $key = Backtester::candleKey($at);
            foreach ($day as $j => $c) { if ($c[Backtester::T] === $key) { $i = $j; break; } }
            if ($i === null) continue;

            $hiSoFar = max(array_column(array_slice($day, 0, $i + 1), Backtester::H));
            $rows[] = [
                'at'      => ((Backtester::sane($firstPrice[$k], $day[$i]) ?: $day[$i][Backtester::C]) / $prev - 1) * 100,
                'so_far'  => ($hiSoFar / $prev - 1) * 100,
                'eod'     => (end($day)[Backtester::C] / $prev - 1) * 100,
                'day_hi'  => (max(array_column($day, Backtester::H)) / $prev - 1) * 100,
            ];
        }

        $n = count($rows);
        if ($n < 10) return ['stock_days' => $n, 'suspicious' => false, 'enough' => false];

        $med = function (array $v) { sort($v); $c = count($v); return $c % 2 ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2; };
        $share = fn (string $f, float $t) => round(count(array_filter($rows, fn ($r) => $r[$f] >= $t)) / $n * 100, 1);

        $at = array_column($rows, 'at'); $eod = array_column($rows, 'eod');
        $gap = $med($eod) - $med($at);
        $ge3 = $share('eod', 3);

        return [
            'enough' => true, 'stock_days' => $n,
            'at_signal' => ['median' => round($med($at), 2), 'min' => round(min($at), 2), 'max' => round(max($at), 2)],
            'eod'       => ['median' => round($med($eod), 2), 'min' => round(min($eod), 2), 'max' => round(max($eod), 2)],
            'day_high_so_far_median' => round($med(array_column($rows, 'so_far')), 2),
            'whole_day_high_median'  => round($med(array_column($rows, 'day_hi')), 2),
            'eod_ge_3' => $ge3, 'eod_ge_5' => $share('eod', 5),
            'at_signal_lt_3_but_eod_ge_5' => round(count(array_filter($rows, fn ($r) => $r['at'] < 3 && $r['eod'] >= 5)) / $n * 100, 1),
            'gap' => round($gap, 2),
            // nearly every flagged day ends up strongly, although the stock had barely moved when it was flagged
            'suspicious' => $ge3 >= 90 && $gap >= 2,
        ];
    }
}
