<?php

namespace App\Services\Live;

use Illuminate\Support\Facades\DB;
use App\Models\LiveOrder;
use App\Models\LivePosition;
use App\Services\Live\Brokers\Broker;

/**
 * Keeps our records in line with what the broker says happened to each order, and turns fills into position changes:
 * a buy fill adds shares (and averages the price), a sell fill removes them and closes the position when none are left.
 */
class OrderSync
{
    public function __construct(private Broker $broker) {}

    /** Bring every order that is still open up to date. @return int orders that changed */
    public function syncOpen(): int
    {
        $n = 0;
        foreach (LiveOrder::where('status', 'open')->orderBy('id')->get() as $o) {
            if ($this->sync($o)) $n++;
        }
        return $n;
    }

    /** @return bool whether anything changed */
    public function sync(LiveOrder $o): bool
    {
        if ($o->status !== 'open' || !$o->broker_order_id) return false;

        try {
            $st = $this->broker->status($o->toArray());
        } catch (\Throwable $e) {
            return false;   // the broker did not answer: look again next time
        }

        $changed = false;
        DB::transaction(function () use ($o, $st, &$changed) {
            $o = LiveOrder::lockForUpdate()->find($o->id);
            if ($o->status !== 'open') return;

            $delta = max(0, (int) $st['filled_qty'] - (int) $o->filled_qty);
            $deltaPrice = null;
            if ($delta > 0 && $st['avg_price'] !== null) {
                // the broker reports the average of everything filled so far: the price of just the new part
                $deltaPrice = ($st['avg_price'] * $st['filled_qty'] - (float) $o->filled_price * (int) $o->filled_qty) / $delta;
            }

            $new = ['filled_qty' => max((int) $o->filled_qty, (int) $st['filled_qty']), 'message' => $st['message']];
            if ($st['avg_price'] !== null) $new['filled_price'] = $st['avg_price'];
            if ($st['status'] !== 'open') $new['status'] = $st['status'];
            $changed = $delta > 0 || $st['status'] !== 'open';
            $new['updated_at'] = Clock::now()->toDateTimeString();
            $o->update($new);

            $pos = LivePosition::lockForUpdate()->find($o->position_id);
            if (!$pos) return;

            if ($delta > 0 && $deltaPrice !== null) {
                $o->side === 'buy' ? $this->buyFill($pos, $delta, $deltaPrice) : $this->sellFill($pos, $delta, $deltaPrice, $o->kind);
            }

            // an order that has ended
            if ($st['status'] !== 'open') {
                $pos = LivePosition::find($pos->id);
                if ($o->kind === 'average' && (int) $new['filled_qty'] > 0) $pos->update(['adds' => $pos->adds + 1, 'updated_at' => Clock::now()->toDateTimeString()]);
                // the first buy never filled at all: there is no position
                if ($o->kind === 'entry' && $pos->status === 'pending' && (int) $new['filled_qty'] === 0) {
                    $pos->update(['status' => $st['status'] === 'rejected' ? 'failed' : 'cancelled', 'closed_at' => Clock::now()->toDateTimeString(), 'updated_at' => Clock::now()->toDateTimeString()]);
                }
            }
        });

        return $changed;
    }

    private function buyFill(LivePosition $pos, int $qty, float $price): void
    {
        $invested = (float) $pos->invested + $qty * $price;
        $total = (int) $pos->qty + $qty;
        $now = Clock::now();

        $v = ['qty' => $total, 'invested' => round($invested, 2), 'avg_price' => round($invested / $total, 4), 'updated_at' => $now->toDateTimeString()];
        if ($pos->status === 'pending') {
            $v['status'] = 'open';
            $v['opened_at'] = $now->toDateTimeString();
            $hold = LiveConfig::get()['hold_days'];
            $v['hold_until'] = $hold === null ? null : Clock::addBusinessDays($now->toDateString(), (int) $hold);   // null = no time exit
        }
        $pos->update($v);
    }

    private function sellFill(LivePosition $pos, int $qty, float $price, string $kind): void
    {
        $now = Clock::now();
        $left = max(0, (int) $pos->qty - $qty);
        $sold = (int) $pos->sold_qty + $qty;
        $value = (float) $pos->exit_value + $qty * $price;

        $v = ['qty' => $left, 'sold_qty' => $sold, 'exit_value' => round($value, 2), 'updated_at' => $now->toDateTimeString()];
        if ($left === 0) {
            $v += ['status' => 'closed', 'closed_at' => $now->toDateTimeString(), 'exit_price' => round($value / $sold, 4),
                   'exit_reason' => $kind, 'pnl' => round($value - (float) $pos->avg_price * $sold, 2)];
        }
        $pos->update($v);
    }
}
