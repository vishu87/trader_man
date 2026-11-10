<?php

namespace App\Services\Live\Brokers;

/**
 * Real market data, SIMULATED orders: nothing is ever sent to Zerodha. An order "fills" when the live price allows it
 * (a market order at once; a limit buy when the price is at or under the limit; a limit sell when at or over it).
 */
class DryRunBroker extends KiteBroker
{
    public function mode(): string { return 'dry_run'; }

    public function place(string $side, string $symbol, int $qty, string $orderType, ?float $price): array
    {
        return ['ok' => true, 'id' => 'DRY-' . strtoupper(bin2hex(random_bytes(4))), 'message' => 'simulated'];
    }

    public function status(array $order): array
    {
        // the engine asked to cancel it (see LiveTick::timeouts): what had filled stays, the rest is gone
        if (($order['message'] ?? '') === 'cancel requested') {
            return ['status' => 'cancelled', 'filled_qty' => (int) ($order['filled_qty'] ?? 0), 'avg_price' => ($order['filled_price'] ?? null) !== null ? (float) $order['filled_price'] : null, 'message' => 'cancelled'];
        }

        $ltp = $this->ltp([$order['symbol']])[$order['symbol']] ?? null;
        if ($ltp === null) return ['status' => 'open', 'filled_qty' => 0, 'avg_price' => null, 'message' => 'no live price'];

        $limit = $order['order_type'] === 'limit' ? (float) $order['price'] : null;
        $fills = $limit === null || ($order['side'] === 'buy' ? $ltp <= $limit : $ltp >= $limit);

        return $fills
            ? ['status' => 'complete', 'filled_qty' => (int) $order['qty'], 'avg_price' => $ltp, 'message' => 'simulated fill at the live price']
            : ['status' => 'open', 'filled_qty' => 0, 'avg_price' => null, 'message' => 'waiting for the price'];
    }

    public function cancel(array $order): bool
    {
        return true;
    }
}
