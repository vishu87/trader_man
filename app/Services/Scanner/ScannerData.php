<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\DB;

/**
 * Reads the stored signals and candles into the shape the Backtester wants.
 */
class ScannerData
{
    /** Strategies to try side by side. Entry / exit rules only: the page's filters (liquidity, caps …) are applied to all of them. */
    const PRESETS = [
        ['id' => 'manual',          'name' => 'MANUAL: ₹10k, average 5/10/15 %, sell winners at the day end, out at cost on day 2', 'p' => ['strategy' => 'manual', 'first_amount' => 10000]],
        ['id' => 'fixed_2k',        'name' => '₹2k per stock · sell at the close',              'p' => ['first_amount' => 2000,  'hold_days' => 0]],
        ['id' => 'fixed_20k',       'name' => '₹20k per stock · sell at the close',             'p' => ['first_amount' => 20000, 'hold_days' => 0]],
        ['id' => 'avg_20k',         'name' => '₹20k + average down at the 20 SMA, then every 3% (₹20k × up to 3) · sell at the close', 'p' => ['first_amount' => 20000, 'add_amount' => 20000, 'max_adds' => 3, 'hold_days' => 0]],
        ['id' => 'stop_target',     'name' => '₹20k · stop 3% / target 5% · else sell at the close', 'p' => ['first_amount' => 20000, 'stop_pct' => 3, 'target_pct' => 5, 'hold_days' => 0]],
        ['id' => 'next_open_20k',   'name' => '₹20k · sell at the NEXT day open',               'p' => ['first_amount' => 20000, 'hold_days' => 1, 'exit_at' => 'open']],
        ['id' => 'next_close_20k',  'name' => '₹20k · sell at the NEXT day close',              'p' => ['first_amount' => 20000, 'hold_days' => 1, 'exit_at' => 'close']],
        ['id' => 'next_close_2k',   'name' => '₹2k · sell at the NEXT day close',               'p' => ['first_amount' => 2000,  'hold_days' => 1, 'exit_at' => 'close']],
        ['id' => 'avg_next_close',  'name' => '₹20k + average down (20 SMA, then 3% steps) · sell at the NEXT day close', 'p' => ['first_amount' => 20000, 'add_amount' => 20000, 'max_adds' => 3, 'hold_days' => 1, 'exit_at' => 'close']],
    ];

    /** The stock presets: buy / sell rules only; filters, slippage and the time window come from the page. */
    public static function presets(): array
    {
        return self::PRESETS;
    }

    /** Signals whose day is within [from, to] (Y-m-d). */
    public static function signals(string $from, string $to): array
    {
        return DB::table('scanner_signals')->whereBetween('trade_date', [$from, $to])->orderBy('signal_at')->get(['symbol', 'signal_at', 'market_cap', 'sector', 'trigger_price'])
            ->map(fn ($r) => ['symbol' => $r->symbol, 'signal_at' => $r->signal_at, 'market_cap' => $r->market_cap, 'sector' => $r->sector, 'trigger_price' => $r->trigger_price !== null ? (float) $r->trigger_price : null])->all();
    }

    /**
     * The candles the signals need — and only those: for each stock, its signal days plus the days a trade could still be open
     * (so a five-month period of 600 stocks loads a few hundred thousand candles, not millions).
     *
     * @param int $holdDays  the longest a position may be held, to know how many days after a signal to read
     * @return array{candles: array, daily: array, names: array}
     */
    public static function load(array $signals, int $holdDays = 1): array
    {
        $out = ['candles' => [], 'daily' => [], 'names' => []];
        if (!$signals) return $out;

        $tail = max(7, $holdDays * 2 + 5);
        $dates = [];
        foreach ($signals as $s) $dates[$s['symbol']][substr($s['signal_at'], 0, 10)] = true;

        $min = $max = null;
        foreach ($dates as $symbol => $set) {
            $days = array_keys($set); sort($days);

            // merge each signal day's [day, day + tail] window into runs
            $ranges = [];
            foreach ($days as $d) {
                $end = date('Y-m-d', strtotime($d . " +{$tail} days"));
                $n = count($ranges);
                if ($n && $d <= $ranges[$n - 1][1]) $ranges[$n - 1][1] = max($ranges[$n - 1][1], $end); else $ranges[] = [$d, $end];
            }
            $min = $min === null ? $days[0] : min($min, $days[0]);
            $max = $max === null ? end($ranges)[1] : max($max, end($ranges)[1]);

            $q = DB::table('scanner_candles')->where('exchange', 'NSE')->where('symbol', $symbol)->where('timeframe', '5min')
                ->where(function ($w) use ($ranges) { foreach ($ranges as [$a, $b]) $w->orWhereBetween('candle_at', [$a . ' 00:00:00', $b . ' 23:59:59']); })
                ->orderBy('candle_at')->select('candle_at', 'open', 'high', 'low', 'close', 'volume');
            foreach ($q->cursor() as $r) {
                $out['candles'][$symbol][] = [$r->candle_at, (float) $r->open, (float) $r->high, (float) $r->low, (float) $r->close, (int) $r->volume];
            }
        }

        // daily candles: a lookback before the first signal (liquidity looks at the sessions before it) through the last window
        $q = DB::table('scanner_candles')->where('exchange', 'NSE')->whereIn('symbol', array_keys($dates))->where('timeframe', 'day')
            ->whereBetween('candle_at', [date('Y-m-d', strtotime($min . ' -75 days')) . ' 00:00:00', $max . ' 23:59:59'])
            ->orderBy('symbol')->orderBy('candle_at')->select('symbol', 'candle_at', 'open', 'high', 'low', 'close', 'volume');
        foreach ($q->cursor() as $r) {
            $out['daily'][$r->symbol][] = [substr($r->candle_at, 0, 10), (float) $r->open, (float) $r->high, (float) $r->low, (float) $r->close, (int) $r->volume];
        }

        $out['names'] = DB::table('scanner_instruments')->whereIn('symbol', array_keys($dates))->pluck('name', 'symbol')->all();

        return $out;
    }

    /** The longest a position may be held under these rules (how far past a signal the candles must reach). */
    public static function holdDays(array $params): int
    {
        return ($params['strategy'] ?? 'manual') === 'rules' ? max(1, (int) ($params['hold_days'] ?? 1)) : max(1, (int) ($params['m_max_hold_days'] ?? 5));
    }

    /** Run one strategy over the signals of a period. */
    public static function backtest(array $params, string $from, string $to, ?array $data = null, ?array $signals = null): array
    {
        $signals = $signals ?? self::signals($from, $to);
        $data = $data ?? self::load($signals, self::holdDays($params));
        return (new Backtester($params))->run($signals, $data['candles'], $data['daily'], $data['names']);
    }
}
