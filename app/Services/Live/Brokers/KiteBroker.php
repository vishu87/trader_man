<?php

namespace App\Services\Live\Brokers;

use Illuminate\Support\Facades\Cache;
use App\Services\KiteService;

/**
 * Zerodha through the Kite Connect client. Market data always comes from here; orders are REAL when this class is used directly
 * (live mode). DryRunBroker extends it and replaces only the order methods.
 */
class KiteBroker implements Broker
{
    /** @var object|null a Kite client (tests inject a fake); null = build one from today's token */
    protected $client;

    public function __construct($client = null)
    {
        $this->client = $client;
    }

    protected function kite()
    {
        return $this->client ??= KiteService::client();
    }

    public function mode(): string { return 'live'; }

    public function ltp(array $symbols): array
    {
        if (!$symbols) return [];
        $out = [];
        $ex = [];
        foreach ($symbols as $s) $ex[$s] = $this->exchangeOf($s);
        $quotes = $this->kite()->getLTP(array_map(fn ($s) => $ex[$s] . ':' . $s, $symbols));
        foreach ($symbols as $s) {
            $key = $ex[$s] . ':' . $s;
            $q = is_array($quotes) ? ($quotes[$key] ?? null) : ($quotes->{$key} ?? null);
            $p = is_array($q) ? ($q['last_price'] ?? null) : ($q->last_price ?? null);
            if ($p !== null && (float) $p > 0) $out[$s] = (float) $p;
        }
        return $out;
    }

    protected function token(string $symbol): ?int
    {
        $ex = $this->exchangeOf($symbol);
        return Cache::remember('live_token_' . $ex . '_' . $symbol, 86400, function () use ($symbol, $ex) {
            $q = $this->kite()->getQuote([$ex . ':' . $symbol]);
            $q = is_array($q) ? ($q[$ex . ':' . $symbol] ?? null) : ($q->{$ex . ':' . $symbol} ?? null);
            $t = is_array($q) ? ($q['instrument_token'] ?? null) : ($q->instrument_token ?? null);
            return $t ? (int) $t : null;
        });
    }

    public function dailyHistory(string $symbol, int $sessions): array
    {
        $token = $this->token($symbol);
        if (!$token) return [];

        $candles = $this->kite()->getHistoricalData($token, 'day', date('Y-m-d', strtotime('-' . ($sessions * 2 + 10) . ' days')), date('Y-m-d'));
        $rows = [];
        foreach ((array) $candles as $c) {
            $date = $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : substr((string) $c->date, 0, 10);
            if ($date >= date('Y-m-d')) continue;   // today's candle is not complete
            $rows[] = [$date, (float) $c->open, (float) $c->high, (float) $c->low, (float) $c->close, (int) $c->volume];
        }
        return array_slice($rows, -$sessions);
    }

    public function recentCandles(string $symbol, int $count): array
    {
        $token = $this->token($symbol);
        if (!$token) return [];

        $now = \App\Services\Live\Clock::now();
        $candles = $this->kite()->getHistoricalData($token, '5minute', $now->copy()->subDays(7)->format('Y-m-d 09:00:00'), $now->format('Y-m-d H:i:s'));
        $rows = [];
        foreach ((array) $candles as $c) {
            $at = $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d H:i:s') : substr(str_replace('T', ' ', (string) $c->date), 0, 19);
            if (strtotime($at) + 300 > $now->timestamp) continue;   // still forming
            $rows[] = [$at, (float) $c->open, (float) $c->high, (float) $c->low, (float) $c->close, (int) $c->volume];
        }
        return array_slice($rows, -$count);
    }

    /** 5-minute candles between two datetimes, the one still forming included — for the day chart only (market data, no orders). */
    public function intraday(string $symbol, string $from, string $to): array
    {
        $token = $this->token($symbol);
        if (!$token) return [];

        $rows = [];
        foreach ((array) $this->kite()->getHistoricalData($token, '5minute', $from, $to) as $c) {
            $at = $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d H:i:s') : substr(str_replace('T', ' ', (string) $c->date), 0, 19);
            $rows[] = [$at, (float) $c->open, (float) $c->high, (float) $c->low, (float) $c->close, (int) $c->volume];
        }
        return $rows;
    }

    /** symbol => company name for one exchange's equities, fetched once a day. */
    protected function names(string $exchange): array
    {
        return Cache::remember('live_names_' . $exchange, 86400, function () use ($exchange) {
            $map = [];
            foreach ((array) $this->kite()->getInstruments($exchange) as $i) {
                $i = (object) (array) $i;
                if (($i->instrument_type ?? '') === 'EQ' && ($i->segment ?? '') === $exchange) $map[(string) $i->tradingsymbol] = (string) ($i->name ?? '');
            }
            return $map;
        });
    }

    /** NSE first; the stock is on BSE only when NSE does not list it. null = neither. If the lists cannot be read, NSE is assumed (nothing is blocked on a lookup failure). */
    public function exchange(string $symbol): ?string
    {
        try {
            if (array_key_exists($symbol, $this->names('NSE'))) return 'NSE';
            if (array_key_exists($symbol, $this->names('BSE'))) return 'BSE';
            return null;
        } catch (\Throwable $e) {
            return 'NSE';
        }
    }

    /** The exchange to talk to about a symbol: where it is listed, NSE when that is not known. */
    protected function exchangeOf(string $symbol): string
    {
        return $this->exchange($symbol) ?? 'NSE';
    }

    public function instrumentName(string $symbol): ?string
    {
        return $this->names($this->exchangeOf($symbol))[$symbol] ?? null;
    }

    public function place(string $side, string $symbol, int $qty, string $orderType, ?float $price): array
    {
        $params = [
            'exchange' => $this->exchangeOf($symbol), 'tradingsymbol' => $symbol, 'transaction_type' => strtoupper($side), 'quantity' => $qty,
            'product' => 'CNC', 'order_type' => strtoupper($orderType), 'validity' => 'DAY',
        ];
        if (strtoupper($orderType) === 'LIMIT') $params['price'] = $price;

        try {
            $r = $this->kite()->placeOrder('regular', $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'message' => mb_substr($e->getMessage(), 0, 200)];
        }
        $id = is_object($r) ? ($r->order_id ?? null) : (is_array($r) ? ($r['order_id'] ?? null) : (is_scalar($r) ? (string) $r : null));
        return $id ? ['ok' => true, 'id' => (string) $id, 'message' => 'placed'] : ['ok' => false, 'id' => null, 'message' => 'Kite did not return an order id'];
    }

    public function status(array $order): array
    {
        $history = (array) $this->kite()->getOrderHistory($order['broker_order_id']);
        $last = end($history);
        if (!$last) return ['status' => 'open', 'filled_qty' => 0, 'avg_price' => null, 'message' => 'no history yet'];
        $get = fn ($k) => is_array($last) ? ($last[$k] ?? null) : ($last->$k ?? null);

        $st = strtoupper((string) $get('status'));
        $status = $st === 'COMPLETE' ? 'complete' : ($st === 'REJECTED' ? 'rejected' : ($st === 'CANCELLED' ? 'cancelled' : 'open'));
        $avg = $get('average_price');
        return ['status' => $status, 'filled_qty' => (int) $get('filled_quantity'), 'avg_price' => $avg ? (float) $avg : null, 'message' => mb_substr((string) ($get('status_message') ?? $st), 0, 200)];
    }

    public function cancel(array $order): bool
    {
        try {
            $this->kite()->cancelOrder('regular', $order['broker_order_id']);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
