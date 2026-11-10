<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\SymbolFib;
use App\Models\SymbolFibLevel;

/**
 * Fibonacci levels for a swing (a low and a high of one symbol), measured the way Kite / TradingView draw them:
 * 0% is where the swing STARTED and 100% where it ENDED, and the extensions project it further in the same direction.
 *
 *   up swing   (low came first):  start = low,  end = high   →  price = low  + ratio × (high - low)
 *   down swing (high came first): start = high, end = low    →  price = high - ratio × (high - low)
 *
 * so for an up swing 100% is the high and 138.2% / 161.8% sit above it; for a down swing 100% is the low and the extensions
 * sit below it. (Kite's own drawing also has 23.6% and 78.6%; they are left out on purpose.)
 */
class FibService
{
    const RETRACEMENTS = [0, 0.382, 0.5, 0.618, 1];
    const EXTENSIONS   = [1.382, 1.618];
    const MAX_PER_SYMBOL = 10;

    /** @return array<int, array{kind: string, ratio: float, price: float}> */
    public static function levels(float $low, float $high, string $direction): array
    {
        [$start, $end] = $direction === 'up' ? [$low, $high] : [$high, $low];
        $out = [];

        foreach (array_merge(self::RETRACEMENTS, self::EXTENSIONS) as $r) {
            $out[] = ['kind' => $r > 1 ? 'extension' : 'retracement', 'ratio' => (float) $r, 'price' => round($start + $r * ($end - $start), 4)];
        }

        return $out;
    }

    /**
     * Look the swing up in the stored DAILY candles: the low of the candle on $lowDate, the high of the one on $highDate.
     * Which date comes first decides the direction.
     *
     * @return array{low: float, high: float, direction: string}
     * @throws \RuntimeException with a message that is fine to show the user
     */
    public static function swingFromDates(string $exchange, string $symbol, string $lowDate, string $highDate): array
    {
        if ($lowDate === $highDate) {
            throw new \RuntimeException('The low and the high are on the same day — pick two different dates');
        }

        $low  = self::candleOn($exchange, $symbol, $lowDate);
        $high = self::candleOn($exchange, $symbol, $highDate);

        return self::swing((float) $low->low, (float) $high->high, $lowDate < $highDate ? 'up' : 'down');
    }

    /** Validate and shape a swing. @throws \RuntimeException */
    public static function swing(float $low, float $high, string $direction): array
    {
        if ($low <= 0 || $high <= 0) {
            throw new \RuntimeException('Prices must be above zero');
        }
        if ($high <= $low) {
            throw new \RuntimeException('The high (' . self::num($high) . ') must be above the low (' . self::num($low) . ')');
        }
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \RuntimeException('Direction must be up or down');
        }

        return ['low' => $low, 'high' => $high, 'direction' => $direction];
    }

    private static function candleOn(string $exchange, string $symbol, string $date)
    {
        $base = DB::table('stock_candles')->where('exchange', $exchange)->where('symbol', $symbol)->where('timeframe', 'daily');

        $c = (clone $base)->where('date', $date)->first(['date', 'low', 'high']);
        if ($c) return $c;

        // say what exists instead of just "not found": a weekend, a holiday or a symbol that was never synced
        if (!(clone $base)->exists()) {
            throw new \RuntimeException("No daily candles stored for {$exchange}:{$symbol} yet — sync its candles first, or enter the prices by hand");
        }
        $before = (clone $base)->where('date', '<', $date)->orderByDesc('date')->value('date');
        $after  = (clone $base)->where('date', '>', $date)->orderBy('date')->value('date');
        $near   = array_filter([$before, $after]);

        throw new \RuntimeException("No daily candle on {$date} (market closed, or beyond the stored data)" . ($near ? ' — nearest trading dates: ' . implode(' and ', $near) : ''));
    }

    // ─── Storing ─────────────────────────────────────────────────────────────

    /**
     * Create (or, with $id, replace) a swing and its levels together.
     *
     * @param array $swing  result of swing() / swingFromDates()
     * @param array $meta   label, low_date, high_date, source, is_shown
     * @throws \RuntimeException
     */
    public static function store(string $exchange, string $symbol, array $swing, array $meta, ?int $id = null): SymbolFib
    {
        if ($id === null && SymbolFib::where('exchange', $exchange)->where('symbol', $symbol)->count() >= self::MAX_PER_SYMBOL) {
            throw new \RuntimeException('Up to ' . self::MAX_PER_SYMBOL . ' Fibonacci swings per symbol — delete one first');
        }

        return DB::transaction(function () use ($exchange, $symbol, $swing, $meta, $id) {
            $values = [
                'label' => ($meta['label'] ?? '') !== '' ? mb_substr((string) $meta['label'], 0, 60) : null,
                'direction' => $swing['direction'], 'low_price' => $swing['low'], 'high_price' => $swing['high'],
                'low_date' => $meta['low_date'] ?? null, 'high_date' => $meta['high_date'] ?? null,
                'source' => $meta['source'] ?? 'dates', 'is_shown' => (int) ($meta['is_shown'] ?? 1), 'updated_at' => now(),
            ];

            if ($id !== null) {
                $fib = SymbolFib::where('id', $id)->where('exchange', $exchange)->where('symbol', $symbol)->first();
                if (!$fib) throw new \RuntimeException('That Fibonacci swing no longer exists');
                $fib->update($values);
            } else {
                $fib = SymbolFib::create(['exchange' => $exchange, 'symbol' => $symbol, 'created_at' => now()] + $values);
            }

            SymbolFibLevel::where('fib_id', $fib->id)->delete();
            SymbolFibLevel::insert(array_map(
                fn ($l) => ['fib_id' => $fib->id, 'exchange' => $exchange, 'symbol' => $symbol] + $l,
                self::levels($swing['low'], $swing['high'], $swing['direction'])
            ));

            return $fib;
        });
    }

    public static function delete(SymbolFib $fib): void
    {
        DB::transaction(function () use ($fib) {
            SymbolFibLevel::where('fib_id', $fib->id)->delete();
            $fib->delete();
        });
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /** One swing as the UI wants it. */
    public static function payload(SymbolFib $fib): array
    {
        $fib->loadMissing('levels');

        return [
            'id' => $fib->id, 'label' => $fib->label, 'direction' => $fib->direction, 'source' => $fib->source,
            'low_price' => (float) $fib->low_price, 'high_price' => (float) $fib->high_price,
            'low_date' => $fib->low_date ? substr((string) $fib->low_date, 0, 10) : null,
            'high_date' => $fib->high_date ? substr((string) $fib->high_date, 0, 10) : null,
            'is_shown' => (bool) $fib->is_shown,
            'levels' => $fib->levels->map(fn ($l) => ['kind' => $l->kind, 'ratio' => (float) $l->ratio, 'price' => (float) $l->price])->all(),
        ];
    }

    /** @return array<int, array> every swing of one symbol */
    public static function forSymbol(string $exchange, string $symbol): array
    {
        return SymbolFib::with('levels')->where('exchange', $exchange)->where('symbol', $symbol)->orderBy('id')->get()
            ->map(fn ($f) => self::payload($f))->all();
    }

    /**
     * What a chart needs to draw every SHOWN swing of the given symbols: the swing itself (start → end, for the trendline) and its
     * levels (for the lines and the shaded bands between them).
     *
     * @param array<int, array{exchange: string, symbol: string}>|null $symbols  (null = all symbols)
     * @return array<string, array<int, array>> keyed 'NSE:INFY'
     */
    public static function chartLines(?array $symbols = null): array
    {
        $query = SymbolFib::with('levels')->where('is_shown', 1);
        if ($symbols !== null) {
            if (!$symbols) return [];
            $query->where(function ($q) use ($symbols) {
                foreach ($symbols as $s) $q->orWhere(fn ($w) => $w->where('exchange', $s['exchange'])->where('symbol', $s['symbol']));
            });
        }

        $out = [];
        foreach ($query->orderBy('id')->get() as $fib) {
            $out[$fib->exchange . ':' . $fib->symbol][] = self::chartSwing($fib);
        }
        return $out;
    }

    /** One swing as a chart draws it. start = where the swing began (0%), end = where it ended (100%). */
    public static function chartSwing(SymbolFib $fib): array
    {
        $up   = $fib->direction === 'up';
        $low  = ['date' => $fib->low_date ? substr((string) $fib->low_date, 0, 10) : null,  'price' => round((float) $fib->low_price, 2)];
        $high = ['date' => $fib->high_date ? substr((string) $fib->high_date, 0, 10) : null, 'price' => round((float) $fib->high_price, 2)];

        return [
            'id' => $fib->id, 'label' => $fib->label, 'direction' => $fib->direction,
            'start' => $up ? $low : $high, 'end' => $up ? $high : $low,
            'levels' => $fib->levels->map(fn ($l) => ['ratio' => (float) $l->ratio, 'price' => round((float) $l->price, 2), 'extension' => $l->kind === 'extension'])->all(),
        ];
    }

    /** 0.618 → "61.8%", 1.272 → "127.2%", 0 → "0%" */
    public static function ratioLabel(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 1, '.', ''), '0'), '.') . '%';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
