<?php

namespace App\Services;

/**
 * The arithmetic of a real trade, from its buys and sells (long only), by the AVERAGE-COST method — the way a broker shows a
 * holding:
 *   - a buy raises the quantity and moves the average cost:  avg = (avg × qty + price × bought) / (qty + bought)
 *   - a sell lowers the quantity at the average cost; it realises (sell price − average cost) × quantity sold
 *   - what is still held is valued at the live price (unrealised); charges of every step reduce the profit
 * Nothing here stores anything: it is always recomputed from the actions, so editing or deleting one can never leave a stale total.
 *
 * Order of actions: by date, and on the same day buys before sells (so a same-day round trip entered sell-first still works),
 * then in the order entered.
 */
class TradeLedger
{
    /** @param array<int, array{id?: int, side: string, qty: int, price: float, charges?: float, action_date: string}> $actions */
    public static function sort(array $actions): array
    {
        usort($actions, fn ($a, $b) =>
            strcmp((string) $a['action_date'], (string) $b['action_date'])
            ?: (($a['side'] === 'buy' ? 0 : 1) <=> ($b['side'] === 'buy' ? 0 : 1))
            ?: (($a['id'] ?? PHP_INT_MAX) <=> ($b['id'] ?? PHP_INT_MAX))
        );
        return $actions;
    }

    /**
     * The first problem in a sequence of actions — selling more than was held — or null when it is fine.
     */
    public static function problem(array $actions): ?string
    {
        $qty = 0;
        foreach (self::sort($actions) as $a) {
            $qty += $a['side'] === 'buy' ? (int) $a['qty'] : -(int) $a['qty'];
            if ($qty < 0) {
                $held = $qty + (int) $a['qty'];
                return "That sells {$a['qty']} on {$a['action_date']} but only {$held} " . ($held === 1 ? 'share was' : 'shares were') . ' held by then';
            }
        }
        return null;
    }

    /**
     * @return array{qty: int, avg_price: float, invested: float, bought_qty: int, bought_value: float, sold_qty: int, sold_value: float,
     *               realised_gross: float, charges: float, realised: float, rows: array<int, array>}
     *         `realised` is after ALL charges (buys and sells). `rows` = the actions in order, each with the quantity / average after it
     *         and, for a sell, the profit it realised.
     */
    public static function compute(array $actions): array
    {
        $qty = 0; $avg = 0.0;
        $boughtQty = $soldQty = 0; $boughtValue = $soldValue = 0.0;
        $gross = 0.0; $charges = 0.0;
        $rows = [];

        foreach (self::sort($actions) as $a) {
            $q = (int) $a['qty']; $p = (float) $a['price']; $c = (float) ($a['charges'] ?? 0);
            $charges += $c;
            $realised = null;

            if ($a['side'] === 'buy') {
                $avg = ($avg * $qty + $p * $q) / ($qty + $q);
                $qty += $q;
                $boughtQty += $q; $boughtValue += $p * $q;
            } else {
                $realised = ($p - $avg) * $q;
                $gross += $realised;
                $qty -= $q;
                $soldQty += $q; $soldValue += $p * $q;
                if ($qty === 0) $avg = 0.0;   // flat again: the next buy starts a fresh average
            }

            $rows[] = $a + ['running_qty' => $qty, 'running_avg' => round($avg, 4), 'realised' => $realised === null ? null : round($realised, 2)];
        }

        return [
            'qty' => $qty, 'avg_price' => round($avg, 4), 'invested' => round($avg * $qty, 2),
            'bought_qty' => $boughtQty, 'bought_value' => round($boughtValue, 2), 'sold_qty' => $soldQty, 'sold_value' => round($soldValue, 2),
            'realised_gross' => round($gross, 2), 'charges' => round($charges, 2), 'realised' => round($gross - $charges, 2),
            'rows' => $rows,
        ];
    }

    /**
     * Put a price on what is still held.
     * @return array{price: ?float, value: ?float, unrealised: ?float, unrealised_pct: ?float, total: ?float, return_pct: ?float}
     */
    public static function mark(array $ledger, ?float $price): array
    {
        if ($ledger['qty'] === 0) {
            // nothing held: all profit is realised
            return ['price' => $price, 'value' => 0.0, 'unrealised' => 0.0, 'unrealised_pct' => null,
                    'total' => $ledger['realised'], 'return_pct' => self::pct($ledger['realised'], $ledger['bought_value'])];
        }
        if ($price === null || $price <= 0) {
            return ['price' => null, 'value' => null, 'unrealised' => null, 'unrealised_pct' => null, 'total' => null, 'return_pct' => null];
        }

        $unrealised = ($price - $ledger['avg_price']) * $ledger['qty'];
        $total = $ledger['realised'] + $unrealised;

        return [
            'price' => $price, 'value' => round($price * $ledger['qty'], 2), 'unrealised' => round($unrealised, 2),
            'unrealised_pct' => round(($price / $ledger['avg_price'] - 1) * 100, 2),
            'total' => round($total, 2), 'return_pct' => self::pct($total, $ledger['bought_value']),
        ];
    }

    private static function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : null;
    }
}
