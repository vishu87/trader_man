<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Turns the stored candles of one stock into what Claude is shown: indicators computed HERE in code (so the model never
 * has to do arithmetic on raw prices) plus compact monthly / weekly / daily candle tables.
 */
class SignalContext
{
    const DAILY_LOAD   = 260;   // ~1 year, enough for the 52-week range
    const WEEKLY_LOAD  = 80;
    const MONTHLY_LOAD = 40;

    // rows actually printed into the prompt (keeps it around 6k tokens)
    const DAILY_SHOWN   = 90;
    const WEEKLY_SHOWN  = 60;
    const MONTHLY_SHOWN = 36;

    const MIN_DAILY = 60;       // below this there is not enough history to say anything useful

    /** @return array{daily: array, weekly: array, monthly: array} each oldest -> newest, as float arrays */
    public static function loadCandles(string $exchange, string $symbol): array
    {
        $load = function (string $tf, int $n) use ($exchange, $symbol) {
            return DB::table('stock_candles')
                ->where('exchange', $exchange)->where('symbol', $symbol)->where('timeframe', $tf)
                ->orderByDesc('date')->limit($n)->get()->reverse()->values()
                ->map(fn ($r) => [
                    'date'   => $r->date,
                    'open'   => (float) $r->open,  'high' => (float) $r->high, 'low' => (float) $r->low, 'close' => (float) $r->close,
                    'volume' => (int) $r->volume,
                    'sma10'  => $r->sma10 !== null ? (float) $r->sma10 : null,
                    'sma20'  => $r->sma20 !== null ? (float) $r->sma20 : null,
                    'sma40'  => $r->sma40 !== null ? (float) $r->sma40 : null,
                    'sma50'  => $r->sma50 !== null ? (float) $r->sma50 : null,
                    'bb_low' => $r->bb_low !== null ? (float) $r->bb_low : null,
                    'bb_high' => $r->bb_high !== null ? (float) $r->bb_high : null,
                    'volume_ma21' => $r->volume_ma21 !== null ? (int) $r->volume_ma21 : null,
                ])->all();
        };

        return [
            'daily'   => $load('daily', self::DAILY_LOAD),
            'weekly'  => $load('weekly', self::WEEKLY_LOAD),
            'monthly' => $load('monthly', self::MONTHLY_LOAD),
        ];
    }

    /**
     * Indicators from the daily candles. $price is the live LTP (falls back to the last close).
     *
     * @param  array $daily oldest -> newest
     * @return array
     */
    public static function features(array $daily, ?float $ltp = null): array
    {
        $n    = count($daily);
        $last = $daily[$n - 1];
        $price = ($ltp !== null && $ltp > 0) ? $ltp : $last['close'];

        // ATR(14): average true range of the last 14 sessions
        $trs = [];
        for ($i = max(1, $n - 14); $i < $n; $i++) {
            $prev = $daily[$i - 1]['close'];
            $trs[] = max($daily[$i]['high'] - $daily[$i]['low'], abs($daily[$i]['high'] - $prev), abs($daily[$i]['low'] - $prev));
        }
        $atr = $trs ? array_sum($trs) / count($trs) : null;

        // 52-week range
        $year = array_slice($daily, -252);
        $high52 = max(array_column($year, 'high'));
        $low52  = min(array_column($year, 'low'));

        // returns over ~1 / 3 / 6 months of sessions
        $ret = function (int $back) use ($daily, $n, $last) {
            return $n - 1 - $back >= 0 ? round(($last['close'] / $daily[$n - 1 - $back]['close'] - 1) * 100, 1) : null;
        };

        $volRatio = ($last['volume_ma21'] ?? 0) > 0 ? round($last['volume'] / $last['volume_ma21'], 2) : null;

        return [
            'price'        => round($price, 2),
            'last_close'   => round($last['close'], 2),
            'last_date'    => $last['date'],
            'atr14'        => $atr !== null ? round($atr, 2) : null,
            'atr_pct'      => $atr !== null ? round($atr / $price * 100, 2) : null,
            'high52'       => round($high52, 2),
            'low52'        => round($low52, 2),
            'from_high_pct' => round(($price / $high52 - 1) * 100, 1),
            'from_low_pct'  => round(($price / $low52 - 1) * 100, 1),
            'ret_1m'       => $ret(21),
            'ret_3m'       => $ret(63),
            'ret_6m'       => $ret(126),
            'volume_ratio' => $volRatio,
            'sma20'        => $last['sma20'],
            'sma50'        => $last['sma50'],
            'swing_highs'  => self::swings($daily, 'high'),
            'swing_lows'   => self::swings($daily, 'low'),
        ];
    }

    /**
     * Last three swing highs / lows in the last 120 sessions: a bar whose high (low) is strictly beyond the 5 bars on
     * each side of it. They are the support / resistance levels a trader would draw.
     */
    public static function swings(array $daily, string $field, int $k = 5, int $window = 120, int $keep = 3): array
    {
        $n = count($daily);
        $found = [];
        for ($i = max($k, $n - $window); $i < $n - $k; $i++) {
            $v = $daily[$i][$field];
            $isSwing = true;
            for ($j = $i - $k; $j <= $i + $k; $j++) {
                if ($j === $i) continue;
                if ($field === 'high' ? $daily[$j]['high'] >= $v : $daily[$j]['low'] <= $v) { $isSwing = false; break; }
            }
            if ($isSwing) $found[] = ['date' => $daily[$i]['date'], 'price' => round($v, 2)];
        }
        return array_slice($found, -$keep);
    }

    /**
     * The text sent to Claude.
     *
     * @param array $candles  result of loadCandles()
     * @param array $facts    ['name' => ?string, 'ltp' => ?float, 'holding' => ?array{qty, avg, buckets}, 'plan' => ?array{buy, stop_loss}, 'max_loss' => float]
     */
    public static function render(string $exchange, string $symbol, array $candles, array $facts): string
    {
        $f = self::features($candles['daily'], $facts['ltp'] ?? null);
        $w = $candles['weekly'] ? $candles['weekly'][count($candles['weekly']) - 1] : null;
        $d = $candles['daily'][count($candles['daily']) - 1];

        $pos = fn ($a, $b) => $a === null || $b === null ? 'n/a' : ($a >= $b ? 'above' : 'below');

        $L = [];
        $L[] = "STOCK: {$exchange}:{$symbol}" . (!empty($facts['name']) ? " ({$facts['name']})" : '');
        $L[] = "TODAY: " . now()->toDateString() . " | last daily candle: {$f['last_date']} | LTP: {$f['price']}";

        $h = $facts['holding'] ?? null;
        $L[] = 'USER POSITION: ' . ($h ? "holds {$h['qty']} shares at average cost {$h['avg']} (in: {$h['buckets']})" : 'not held');
        $p = $facts['plan'] ?? null;
        $L[] = 'USER PLAN: ' . ($p ? 'buy ' . ($p['buy'] ?? '—') . ', stop loss ' . ($p['stop_loss'] ?? '—') : 'none set');
        $L[] = 'RISK LIMIT: the user accepts losing at most ₹' . number_format($facts['max_loss'], 0) . ' on one trade';

        $L[] = '';
        $L[] = 'COMPUTED (daily unless stated):';
        $L[] = "- ATR(14): {$f['atr14']} ({$f['atr_pct']}% of price)";
        $L[] = "- 52-week high {$f['high52']} ({$f['from_high_pct']}% from it), low {$f['low52']} ({$f['from_low_pct']}% above it)";
        $L[] = '- Returns: 1m ' . ($f['ret_1m'] ?? 'n/a') . '%, 3m ' . ($f['ret_3m'] ?? 'n/a') . '%, 6m ' . ($f['ret_6m'] ?? 'n/a') . '%';
        $L[] = '- Last session volume vs its 21-day average: ' . ($f['volume_ratio'] ?? 'n/a') . 'x';
        $L[] = "- Trend: close {$pos($d['close'], $d['sma20'])} the 20-SMA ({$d['sma20']}), {$pos($d['close'], $d['sma50'])} the 50-SMA ({$d['sma50']}); 20-SMA is {$pos($d['sma20'], $d['sma50'])} the 50-SMA";
        if ($w) {
            $L[] = "- Weekly: close {$pos($w['close'], $w['sma40'])} the 40-week SMA (~200 days) ({$w['sma40']}), {$pos($w['close'], $w['sma10'])} the 10-week SMA ({$w['sma10']})";
        }
        $fmtSwing = fn ($list) => $list ? implode(', ', array_map(fn ($s) => "{$s['price']} ({$s['date']})", $list)) : 'none';
        $L[] = '- Recent swing highs (resistance): ' . $fmtSwing($f['swing_highs']);
        $L[] = '- Recent swing lows (support): ' . $fmtSwing($f['swing_lows']);

        $table = function (string $title, array $rows, array $cols, int $shown) {
            $rows = array_slice($rows, -$shown);
            $out = ['', $title . ' (oldest -> newest)', implode(',', array_keys($cols))];
            foreach ($rows as $r) {
                $out[] = implode(',', array_map(fn ($fn) => $fn($r), array_values($cols)));
            }
            return $out;
        };
        $n   = fn ($v) => $v === null ? '' : round($v, 2);
        $vol = fn ($r) => (int) round($r['volume'] / 1000);

        $L = array_merge($L,
            $table('MONTHLY CANDLES', $candles['monthly'], [
                'date' => fn ($r) => substr($r['date'], 0, 7), 'o' => fn ($r) => $n($r['open']), 'h' => fn ($r) => $n($r['high']), 'l' => fn ($r) => $n($r['low']), 'c' => fn ($r) => $n($r['close']),
                'sma10' => fn ($r) => $n($r['sma10']), 'sma20' => fn ($r) => $n($r['sma20']),
            ], self::MONTHLY_SHOWN),
            $table('WEEKLY CANDLES (week start date)', $candles['weekly'], [
                'date' => fn ($r) => $r['date'], 'o' => fn ($r) => $n($r['open']), 'h' => fn ($r) => $n($r['high']), 'l' => fn ($r) => $n($r['low']), 'c' => fn ($r) => $n($r['close']), 'vol_k' => $vol,
                'sma10' => fn ($r) => $n($r['sma10']), 'sma20' => fn ($r) => $n($r['sma20']), 'sma40' => fn ($r) => $n($r['sma40']),
            ], self::WEEKLY_SHOWN),
            $table('DAILY CANDLES', $candles['daily'], [
                'date' => fn ($r) => $r['date'], 'o' => fn ($r) => $n($r['open']), 'h' => fn ($r) => $n($r['high']), 'l' => fn ($r) => $n($r['low']), 'c' => fn ($r) => $n($r['close']), 'vol_k' => $vol,
                'sma20' => fn ($r) => $n($r['sma20']), 'sma50' => fn ($r) => $n($r['sma50']), 'bb_low' => fn ($r) => $n($r['bb_low']), 'bb_high' => fn ($r) => $n($r['bb_high']),
            ], self::DAILY_SHOWN)
        );

        return implode("\n", $L);
    }
}
