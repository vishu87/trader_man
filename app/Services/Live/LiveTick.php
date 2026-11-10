<?php

namespace App\Services\Live;

use App\Models\AppSetting;
use App\Models\LiveOrder;
use App\Models\LivePosition;
use App\Models\LiveSignal;
use App\Services\Live\Brokers\Broker;

/**
 * The once-a-minute job (cron → `php artisan live:tick`) that looks after what we hold:
 *   1. picks up any signal that has not been processed (a safety net: the webhook normally triggers processing at once)
 *   2. brings every open order up to date with the broker
 *   3. cancels buys that did not fill in time, and sells that did not fill (they are re-tried)
 *   4. for every position: a manual exit asked for on the page, the target, the stop loss (off by default) and the time exit (off by default) — placing the sell order when one is due
 *   5. for every position still held: the extra buys (1st at the 5-minute 20 SMA, then every few % under the last buy)
 *
 * Exits keep running when trading is switched off: switching off stops NEW buys, it never leaves a position unwatched.
 */
class LiveTick
{
    const MAX_REJECTED_EXITS = 3;   // this many refused sells in 15 minutes and we stop retrying until someone looks

    private OrderSync $sync;
    private SignalProcessor $processor;

    public function __construct(private Broker $broker)
    {
        $this->sync = new OrderSync($broker);
        $this->processor = new SignalProcessor($broker, $this->sync);
    }

    /** @return array<string,mixed> what happened, for the log and the page */
    public function run(): array
    {
        $now = Clock::now();
        $cfg = LiveConfig::get();
        $out = ['at' => $now->toDateTimeString(), 'mode' => $this->broker->mode(), 'market_open' => Clock::marketOpen($now),
                'signals' => 0, 'orders_updated' => 0, 'cancelled' => 0, 'exits' => [], 'averages' => [], 'stuck' => [], 'attention' => []];

        AppSetting::put('live_last_tick', $now->toDateTimeString());
        if (!$out['market_open']) return $out;

        // interrupted work: never retried by itself (a real order may or may not have gone out) — it is flagged for a person
        foreach (LiveSignal::where('status', 'working')->where('processed_at', '<', $now->copy()->subMinutes(5)->toDateTimeString())->get() as $s) {
            $s->update(['status' => 'failed', 'reason' => 'interrupted — check the broker for this stock', 'processed_at' => $now->toDateTimeString()]);
            $out['stuck'][] = 'signal ' . $s->symbol;
        }
        foreach (LiveOrder::where('status', 'sending')->where('placed_at', '<', $now->copy()->subMinutes(2)->toDateTimeString())->get() as $o) {
            $o->update(['status' => 'unknown', 'message' => 'was being sent when the process stopped — check the broker', 'updated_at' => $now->toDateTimeString()]);
            $out['stuck'][] = 'order ' . $o->symbol;
        }

        $out['signals'] = $this->processor->processNew();
        $out['orders_updated'] = $this->sync->syncOpen();
        $out['cancelled'] = $this->timeouts($cfg, $now);
        $out['exits'] = $this->exits($cfg, $now, $out['attention']);
        $out['orders_updated'] += $this->sync->syncOpen();   // simulated / instant fills of what was just sent
        $out['averages'] = $this->processor->checkAverages($cfg);
        $out['orders_updated'] += $this->sync->syncOpen();

        return $out;
    }

    /** Orders that sat unfilled too long are cancelled. @return int */
    private function timeouts(array $cfg, $now): int
    {
        $n = 0;
        foreach (LiveOrder::where('status', 'open')->orderBy('id')->get() as $o) {
            $limit = $o->side === 'buy' ? $cfg['entry_timeout_minutes'] : $cfg['exit_timeout_minutes'];
            if ($o->placed_at > $now->copy()->subMinutes($limit)->toDateTimeString()) continue;

            if ($this->cancelOrder($o, $now)) $n++;
        }
        return $n;
    }

    private function cancelOrder(LiveOrder $o, $now): bool
    {
        if (!$this->broker->cancel($o->toArray())) return false;
        $o->update(['message' => 'cancel requested', 'updated_at' => $now->toDateTimeString()]);
        $this->sync->sync($o->fresh());   // a final look: it may have filled in the meantime
        return true;
    }

    /** Stop / target / time exit for every open position. @return string[] what was placed */
    private function exits(array $cfg, $now, array &$attention): array
    {
        $placed = [];
        $positions = LivePosition::where('status', 'open')->where('qty', '>', 0)->orderBy('id')->get();
        if ($positions->isEmpty()) return $placed;

        $prices = $this->broker->ltp($positions->pluck('symbol')->unique()->values()->all());

        foreach ($positions as $pos) {
            // opened in the other mode (dry run vs live): never sold through the wrong broker
            if ($pos->mode !== $this->broker->mode()) { $attention[] = $pos->symbol . ': opened in ' . str_replace('_', ' ', $pos->mode) . ' mode, so it is not sold while the mode is ' . str_replace('_', ' ', $this->broker->mode()); continue; }

            $ltp = $prices[$pos->symbol] ?? null;
            if (!$ltp) continue;
            $pos->update(['last_price' => $ltp, 'last_checked_at' => $now->toDateTimeString()]);

            // an exit is already on its way
            if (LiveOrder::where('position_id', $pos->id)->where('side', 'sell')->whereIn('status', ['sending', 'open'])->exists()) continue;
            // the broker keeps refusing our sell: stop adding to the pile and say so
            if (LiveOrder::where('position_id', $pos->id)->where('side', 'sell')->where('status', 'rejected')->where('placed_at', '>=', $now->copy()->subMinutes(15)->toDateTimeString())->count() >= self::MAX_REJECTED_EXITS) {
                $attention[] = $pos->symbol . ': the broker refused the sell ' . self::MAX_REJECTED_EXITS . ' times — sell it by hand';
                continue;
            }

            // asked for by hand: wait until the buys still open are cancelled, then sell
            if ($pos->exit_requested_at && LiveOrder::where('position_id', $pos->id)->where('side', 'buy')->whereIn('status', ['sending', 'open'])->exists()) continue;

            $avg = (float) $pos->avg_price;
            $kind = null;
            if ($pos->exit_requested_at) $kind = 'manual';
            elseif ($cfg['stop_pct'] !== null && $ltp <= $avg * (1 - $cfg['stop_pct'] / 100)) $kind = 'stop';
            elseif ($cfg['target_pct'] !== null && $ltp >= $avg * (1 + $cfg['target_pct'] / 100)) $kind = 'target';
            elseif ($pos->hold_until && $now->toDateString() >= $pos->hold_until && $now->format('H:i') >= $cfg['exit_time']) $kind = 'time_exit';
            if (!$kind) continue;

            $order = $this->placeExit($pos, $kind, $cfg, $ltp);
            $placed[] = "{$pos->symbol} {$kind}" . ($order->status === 'rejected' ? ' (refused: ' . $order->message . ')' : '');
        }

        return $placed;
    }

    /** Send the sell of everything still held. */
    private function placeExit(LivePosition $pos, string $kind, array $cfg, float $ltp): LiveOrder
    {
        $type = $cfg['order_type'];
        $price = $type === 'market' ? round($ltp, 2) : SignalProcessor::tick($ltp * (1 - $cfg['exit_buffer_pct'] / 100), false);
        return $this->processor->send(null, $pos, 'sell', $kind, (int) $pos->qty, $type, $price);
    }

    /**
     * The Exit button: close a position by hand. Buys still waiting are cancelled, then what is held is sold. If the sell does not fill
     * (or the broker is not reachable right now) the once-a-minute check keeps trying — the position is marked exit_requested_at.
     * @return array{ok: bool, message: string}
     */
    public function manualExit(int $positionId): array
    {
        $cfg = LiveConfig::get();
        $now = Clock::now();

        $pos = LivePosition::find($positionId);
        if (!$pos || !in_array($pos->status, ['pending', 'open'], true)) return ['ok' => false, 'message' => 'This position is not open any more'];
        if ($pos->mode !== $this->broker->mode()) {
            return ['ok' => false, 'message' => 'It was opened in ' . str_replace('_', ' ', $pos->mode) . ' mode, but the mode is now ' . str_replace('_', ' ', $this->broker->mode()) . ' — switch the mode back to close it'];
        }
        if (LiveOrder::where('position_id', $pos->id)->where('side', 'sell')->whereIn('status', ['sending', 'open'])->exists()) return ['ok' => true, 'message' => 'A sell order is already on its way'];

        $pos->update(['exit_requested_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString()]);

        // stop buying more: cancel the buys still waiting at the broker
        foreach (LiveOrder::where('position_id', $pos->id)->where('side', 'buy')->where('status', 'open')->get() as $o) $this->cancelOrder($o, $now);

        $pos = $pos->fresh();
        if ($pos->status !== 'open' || (int) $pos->qty < 1) {
            return ['ok' => true, 'message' => $pos->status === 'open' ? 'Exit requested' : 'The buy order was cancelled — nothing was bought'];
        }
        if (LiveOrder::where('position_id', $pos->id)->where('side', 'buy')->whereIn('status', ['sending', 'open'])->exists()) {
            return ['ok' => true, 'message' => 'Exit requested — the sell goes out as soon as the waiting buy order is cancelled'];
        }

        $ltp = $this->broker->ltp([$pos->symbol])[$pos->symbol] ?? null;
        if (!$ltp) return ['ok' => true, 'message' => 'Exit requested, but there is no live price right now — the next minute’s check sells it'];

        $order = $this->placeExit($pos, 'manual', $cfg, $ltp);
        $this->sync->sync($order);
        if ($order->status === 'rejected') return ['ok' => false, 'message' => 'The broker refused the sell: ' . $order->message . ' (it will be re-tried every minute)'];

        $o = $order->fresh();
        return ['ok' => true, 'message' => $o->status === 'complete' ? 'Sold ' . $o->filled_qty . ' of ' . $pos->symbol . ' at ₹' . round((float) $o->filled_price, 2) : 'Sell order placed for ' . $pos->qty . ' of ' . $pos->symbol];
    }
}
