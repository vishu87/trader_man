<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\Live\Brokers\KiteBroker;

/**
 * One stock's 5-minute candles for one day, for the day chart (Live Signals and Scanner). Display only: no orders, no trading rules.
 * Each candle carries "sma" = the average of the 20 closes BEFORE it (the level the 2nd buy waits for) and "rsi" = the 14-period RSI
 * (Wilder's smoothing) at its close, both computed across sessions so the first candles of a day are not blank, and "vwap" = the day's
 * VWAP up to and including it (typical price × volume, restarting each session).
 */
class DayCandles
{
    /**
     * @param bool $storedFirst true = the stored backtest candles first, Zerodha when they are missing; false = the other way round
     * @return array{ok: bool, message?: string, source?: string, prev_close?: ?float, candles?: array}
     */
    public static function forDay(string $symbol, string $date, bool $storedFirst): array
    {
        $from = date('Y-m-d', strtotime($date . ' -9 days')) . ' 09:00:00';
        $to   = $date . ' 15:30:00';

        $stored = fn () => DB::table('scanner_candles')->where('exchange', 'NSE')->where('symbol', $symbol)->where('timeframe', '5min')->whereBetween('candle_at', [$from, $to])->orderBy('candle_at')->get()
            ->map(fn ($c) => [$c->candle_at, (float) $c->open, (float) $c->high, (float) $c->low, (float) $c->close, (int) $c->volume])->all();
        $hasDay = fn (array $rows) => (bool) array_filter($rows, fn ($r) => substr($r[0], 0, 10) === $date);

        $error = null;
        $zerodha = function () use ($symbol, $from, $to, &$error) {
            try { return (new KiteBroker())->intraday($symbol, $from, $to); } catch (\Throwable $e) { $error = $e->getMessage(); return []; }
        };

        if ($storedFirst) { $rows = $stored(); $source = 'stored backtest candles'; if (!$hasDay($rows)) { $rows = $zerodha(); $source = 'Zerodha'; } }
        else              { $rows = $zerodha(); $source = 'Zerodha'; if (!$hasDay($rows)) { $rows = $stored(); $source = 'stored backtest candles'; } }

        $rsi = self::rsi(array_column($rows, 4));
        $vwap = []; $day = null; $pv = 0.0; $vol = 0.0;
        foreach ($rows as $j => $r) {
            if (substr($r[0], 0, 10) !== $day) { $day = substr($r[0], 0, 10); $pv = 0.0; $vol = 0.0; }
            $pv += (($r[2] + $r[3] + $r[4]) / 3) * $r[5];
            $vol += $r[5];
            $vwap[$j] = $vol > 0 ? round($pv / $vol, 2) : null;
        }
        $candles = []; $prevClose = null;
        foreach ($rows as $j => $r) {
            $d = substr($r[0], 0, 10);
            if ($d < $date) { $prevClose = $r[4]; continue; }
            if ($d > $date) continue;
            $sma = null;
            if ($j >= 20) { $sum = 0.0; for ($k = $j - 20; $k < $j; $k++) $sum += $rows[$k][4]; $sma = round($sum / 20, 2); }
            $candles[] = ['t' => substr($r[0], 11, 5), 'o' => $r[1], 'h' => $r[2], 'l' => $r[3], 'c' => $r[4], 'v' => $r[5], 'sma' => $sma, 'rsi' => $rsi[$j], 'vwap' => $vwap[$j]];
        }
        if (!$candles) {
            return ['ok' => false, 'message' => 'No 5-minute candles for ' . $symbol . ' on ' . $date . ($error ? ' (Zerodha: ' . mb_substr($error, 0, 120) . ')' : ' — a holiday, a weekend, or not downloaded yet')];
        }

        return ['ok' => true, 'source' => $source, 'prev_close' => $prevClose, 'candles' => $candles];
    }

    /**
     * RSI by Wilder's method: the first value is at index $period (the average gain and loss of the first $period changes), then each
     * value smooths the next. @param float[] $closes @return array<int, ?float> same length, null until there is enough history
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        $out = array_fill(0, count($closes), null);
        if (count($closes) <= $period) return $out;

        $gain = 0.0; $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d > 0) $gain += $d; else $loss -= $d;
        }
        $gain /= $period; $loss /= $period;
        $out[$period] = self::rsiValue($gain, $loss);

        for ($i = $period + 1; $i < count($closes); $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $gain = ($gain * ($period - 1) + max($d, 0)) / $period;
            $loss = ($loss * ($period - 1) + max(-$d, 0)) / $period;
            $out[$i] = self::rsiValue($gain, $loss);
        }
        return $out;
    }

    private static function rsiValue(float $gain, float $loss): float
    {
        if ($loss == 0.0) return $gain == 0.0 ? 50.0 : 100.0;
        return round(100 - 100 / (1 + $gain / $loss), 1);
    }
}
