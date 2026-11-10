<?php

namespace App\Services\Live\Brokers;

/**
 * Everything the live engine needs from a broker. Two implementations: KiteBroker (real orders) and DryRunBroker (real prices,
 * simulated orders). The engine never talks to Kite directly, so it can be tested with a fake.
 */
interface Broker
{
    /** 'dry_run' | 'live' */
    public function mode(): string;

    /** @return array<string,float> symbol => last traded price; a symbol with no price is left out */
    public function ltp(array $symbols): array;

    /** Completed daily sessions before today, oldest first, at most $sessions of them. @return array[] [[date, open, high, low, close, volume], …] */
    public function dailyHistory(string $symbol, int $sessions): array;

    /** The last $count COMPLETED 5-minute candles (the one still forming is left out), oldest first, across sessions. @return array[] [[datetime, open, high, low, close, volume], …] */
    public function recentCandles(string $symbol, int $count): array;

    /** Where the stock trades: 'NSE' (looked at first), else 'BSE'; null when neither lists it. */
    public function exchange(string $symbol): ?string;

    public function instrumentName(string $symbol): ?string;

    /** @return array{ok: bool, id: ?string, message: string} */
    public function place(string $side, string $symbol, int $qty, string $orderType, ?float $price): array;

    /**
     * Where an order we placed stands. $order = the live_orders row as an array.
     * @return array{status: string, filled_qty: int, avg_price: ?float, message: string}  status: open | complete | rejected | cancelled
     */
    public function status(array $order): array;

    public function cancel(array $order): bool;
}
