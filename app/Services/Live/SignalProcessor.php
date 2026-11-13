<?php

namespace App\Services\Live;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\LiveOrder;
use App\Models\LivePosition;
use App\Models\LiveSignal;
use App\Services\Live\Brokers\Broker;

/**
 * Takes each live signal from arrival to a buy (or to a reason it was not bought):
 *
 *   new ──► (its 5-minute candle closes) classified by SignalClassifier ──► verdict
 *     Can enter ─► bought                        (status done)
 *               └► blocked by a limit / no free slot ─► status enter: waits, and is VALIDATED AGAIN before it is bought
 *     Watch     ─► status watching: re-classified on every closed candle until it becomes Can enter or runs out of time
 *     Skip      ─► status skipped
 *   ignored: the stock is already held, an entry order is already placed, or an earlier signal for it is still waiting
 *   expired: the buying window ended while it was still waiting (the whole day is watched: ChartInk sends a stock only once), or — with
 *            "watch the whole day" off — it was still waiting signal_valid_minutes after its candle closed
 *
 * Every order is written down BEFORE it is sent (status 'sending'), so a crash can never leave a real order we do not know about.
 * Nothing here reads any backtesting code. Extra buys (averages) follow the price and are checked by checkAverages().
 */
class SignalProcessor
{
    private SignalClassifier $classifier;

    public function __construct(private Broker $broker, private ?OrderSync $sync = null)
    {
        $this->sync = $sync ?? new OrderSync($broker);
        $this->classifier = new SignalClassifier($broker);
    }

    const WAITING = ['new', 'watching', 'enter'];   // statuses of a signal that has not reached an end yet

    /** Work through the signals that are not finished. @return int how many were handled */
    public function processNew(int $limit = 80): int
    {
        $n = 0;
        foreach (LiveSignal::whereIn('status', self::WAITING)->orderBy('id')->limit($limit)->get() as $s) {
            $cfg = LiveConfig::get();
            $now = Clock::now();

            if ($s->status === 'new' && $this->ignore($s)) { $n++; continue; }
            if ($s->status !== 'new' && $this->expire($s, $cfg, $now)) { $n++; continue; }
            if (!$this->due($s, $cfg, $now)) continue;

            // claim it: if another process got there first, leave it to them
            $prev = $s->status;
            if (LiveSignal::where('id', $s->id)->where('status', $prev)->update(['status' => 'working', 'processed_at' => $now->toDateTimeString()]) !== 1) continue;
            $s->status = 'working';

            try {
                if ($this->process($s->fresh(), $cfg, $prev) === false) {   // not ready yet: look again
                    LiveSignal::where('id', $s->id)->where('status', 'working')->update(['status' => $prev]);
                    continue;
                }
            } catch (\Throwable $e) {
                $this->finish($s, 'failed', 'error: ' . mb_substr($e->getMessage(), 0, 150));
            }
            $n++;
        }
        return $n;
    }

    const CLOSE_GRACE_SECONDS = 3;    // after a candle's end, give the broker a moment to publish it
    const CANDLE_WAIT_MINUTES = 3;    // …and give up on it this long after the close

    /** The 5-minute candle an alert fired in: [start, end]. */
    public static function candleStart(LiveSignal $s): Carbon
    {
        $t = Carbon::parse($s->triggered_at);
        return $t->copy()->setTime((int) $t->format('H'), (int) $t->format('i') - ((int) $t->format('i') % 5), 0);
    }

    public static function candleEnd(LiveSignal $s): Carbon
    {
        return self::candleStart($s)->addMinutes(5);
    }

    private function finish(LiveSignal $s, string $status, ?string $reason = null, ?string $action = null, ?int $positionId = null): void
    {
        $u = ['status' => $status, 'reason' => $reason !== null ? mb_substr($reason, 0, 190) : null, 'action' => $action, 'position_id' => $positionId, 'processed_at' => Clock::now()->toDateTimeString()];
        if ($status === 'ignored') $u['ignored'] = 1;   // ignored is also a field of its own: the lists leave these out
        $s->update($u);
    }

    private function skip(LiveSignal $s, string $reason): void
    {
        $this->finish($s, 'skipped', $reason);
    }

    /** Still waiting (Watch / Can enter): the status and the latest reason, no end yet. */
    private function wait(LiveSignal $s, string $status, string $reason): void
    {
        $s->update(['status' => $status, 'reason' => mb_substr($reason, 0, 190)]);
    }

    // ─── ignored: a signal for a stock we already hold or are already waiting to buy ──

    private function ignore(LiveSignal $s): bool
    {
        $why = null; $of = null;
        $pos = LivePosition::whereIn('status', ['pending', 'open'])->where('symbol', $s->symbol)->first();
        if ($pos) {
            $why = ($pos->status === 'pending' ? 'an entry order for this stock is already placed' : 'already in the trade') . ' (position #' . $pos->id . ') — extra buys follow the price, not new alerts';
        } else {
            $other = LiveSignal::where('symbol', $s->symbol)->where('id', '<', $s->id)->whereIn('status', ['new', 'working', 'watching', 'enter'])->orderBy('id')->first();
            if ($other) { $why = 'signal #' . $other->id . ' for this stock is already waiting for its entry'; $of = $other->id; }
        }
        if (!$why) return false;

        return LiveSignal::where('id', $s->id)->where('status', 'new')->update(['status' => 'ignored', 'ignored' => 1, 'repeat_of' => $of, 'reason' => $why, 'processed_at' => Clock::now()->toDateTimeString()]) === 1;
    }

    // ─── expired: out of time ────────────────────────────────────────────────

    private function expire(LiveSignal $s, array $cfg, Carbon $now): bool
    {
        // the buying window of the DAY THE SIGNAL CAME in (a signal left over from an earlier day must not live on into today)
        $closes = Carbon::parse($s->triggered_at)->setTimeFromTimeString($cfg['entry_until']);
        $deadline = $closes; $why = 'the buying window ended (' . $cfg['entry_until'] . ')';
        if (!$cfg['watch_all_day']) {
            $limit = self::candleEnd($s)->addMinutes((int) $cfg['signal_valid_minutes']);
            if ($limit->lt($closes)) { $deadline = $limit; $why = 'it was still waiting ' . $cfg['signal_valid_minutes'] . ' min after its candle closed'; }
        }
        if ($now->lte($deadline)) return false;

        $last = $s->reason ? ' — last: ' . $s->reason : '';
        return LiveSignal::where('id', $s->id)->whereIn('status', ['watching', 'enter'])->update(['status' => 'expired', 'reason' => mb_substr('not bought: ' . $why . $last, 0, 190), 'processed_at' => $now->toDateTimeString()]) === 1;
    }

    // ─── is there anything to do for this signal right now? ──────────────────

    private function due(LiveSignal $s, array $cfg, Carbon $now): bool
    {
        // we trade on CLOSED candles only: the alert's candle must have closed
        if ($s->status === 'new') return $now->gte(self::candleEnd($s)->addSeconds(self::CLOSE_GRACE_SECONDS));

        // a new candle has closed since it was last looked at
        $latest = SignalClassifier::latestClosed($now)->format('Y-m-d H:i:s');
        if ($s->checked_candle === null || $latest > $s->checked_candle) return true;

        // Can enter but held back by a limit: look again once there is room (it is then validated again before the buy)
        return $s->status === 'enter' && $cfg['enabled'] && $this->limitReason($cfg, (float) $cfg['first_amount'], true) === null;
    }

    // ─── classify, then act on the verdict ───────────────────────────────────

    /** @return bool|null false = not ready yet (leave the signal as it was), otherwise it was handled */
    public function process(LiveSignal $s, array $cfg, string $prev = 'new'): ?bool
    {
        $now = Clock::now();

        if (!in_array($s->alert_name, $cfg['alert_names'], true)) { $this->skip($s, 'alert "' . $s->alert_name . '" is not enabled'); return true; }
        if ($prev === 'new' && (!$now->isWeekday() || $now->format('H:i') < $cfg['entry_from'] || $now->format('H:i') > $cfg['entry_until'])) {
            $this->skip($s, 'outside the buying window (' . $cfg['entry_from'] . '–' . $cfg['entry_until'] . ')'); return true;
        }

        // the stock may have been bought meanwhile by another route
        if ($pos = LivePosition::whereIn('status', ['pending', 'open'])->where('symbol', $s->symbol)->first()) {
            $this->finish($s, 'ignored', 'already in the trade (position #' . $pos->id . ')'); return true;
        }

        // where it trades: NSE, else BSE (a stock neither lists cannot be judged or bought)
        $ex = $this->broker->exchange($s->symbol);
        if ($ex === null) { $this->skip($s, 'not found on NSE or BSE'); return true; }
        if ($s->exchange !== $ex) { $s->update(['exchange' => $ex]); $s->exchange = $ex; }

        // look at it: on a new closed candle, or (a Can enter that was held back) once more just before the buy
        $latest = SignalClassifier::latestClosed($now);
        $isNewCandle = $s->checked_candle === null || $latest->format('Y-m-d H:i:s') > $s->checked_candle;
        $kind = $isNewCandle ? 'candle' : 'recheck';

        $r = $this->classifier->evaluate($s, $cfg, $latest, $kind);

        if ($r['verdict'] === 'nodata') {
            if ($prev === 'new' && $now->lt(self::candleEnd($s)->addMinutes(self::CANDLE_WAIT_MINUTES))) return false;   // the broker has not published it yet
            if ($prev !== 'new') return false;                                                                            // try again next time; expiry ends it
            SignalClassifier::record($s, $r, $kind);
            $this->skip($s, $r['note']); return true;
        }

        SignalClassifier::record($s, $r, $kind);
        $s->refresh();

        if ($r['verdict'] === 'skip') { $this->skip($s, $r['note']); return true; }

        // judging goes on while trading is off (the page shows it), but nothing is queued or bought
        if (!$cfg['enabled']) { $this->skip($s, 'trading is switched off — it was rated: ' . $r['verdict'] . ($r['note'] ? ' (' . $r['note'] . ')' : '')); return true; }

        if ($r['verdict'] === 'watch') { $this->wait($s, 'watching', $r['note']); return true; }

        // Can enter
        $this->enter($s, $cfg, $r['ltp']);
        return true;
    }

    // ─── a stock we do not hold ──────────────────────────────────────────────

    private function enter(LiveSignal $s, array $cfg, ?float $ltp): void
    {
        $symbol = $s->symbol;

        if (!$ltp) { $this->skip($s, 'no live price from the broker'); return; }
        if ($cfg['exclude_etf'] && self::looksLikeEtf($symbol, $this->broker->instrumentName($symbol))) { $this->skip($s, 'ETF / fund'); return; }
        if ($ltp < $cfg['min_price']) { $this->skip($s, 'price ₹' . round($ltp, 2) . ' is under the minimum ₹' . $cfg['min_price']); return; }

        if ($cfg['min_turnover'] > 0) {
            $hist = $this->broker->dailyHistory($symbol, $cfg['liquidity_days']);
            if (count($hist) < 5) { $this->skip($s, 'not enough daily history to check liquidity'); return; }
            $turn = array_sum(array_map(fn ($r) => $r[4] * $r[5], $hist)) / count($hist);
            if ($turn < $cfg['min_turnover']) { $this->skip($s, 'illiquid: average ₹' . number_format($turn / 10000000, 2) . ' cr a day'); return; }
        }

        $amount = (float) $cfg['first_amount'];
        // no room (open positions / money limits): it stays Can enter and is validated again, then bought, when there is room
        if ($why = $this->limitReason($cfg, $amount, true)) { $this->wait($s, 'enter', $why . ' — waiting for room'); return; }

        [$type, $price] = $this->buyOrder($cfg, $ltp);
        $qty = (int) floor($amount / $price);
        if ($qty < 1) { $this->skip($s, 'price ₹' . $price . ' is above the ₹' . number_format($amount) . ' first buy'); return; }

        $pos = LivePosition::create(['exchange' => $s->exchange ?: 'NSE', 'symbol' => $symbol, 'mode' => $this->broker->mode(), 'status' => 'pending', 'signal_id' => $s->id,
                                     'created_at' => Clock::now()->toDateTimeString(), 'updated_at' => Clock::now()->toDateTimeString()]);

        $order = $this->send($s, $pos, 'buy', 'entry', $qty, $type, $price);
        if ($order->status === 'rejected') {
            $pos->update(['status' => 'failed', 'closed_at' => Clock::now()->toDateTimeString()]);
            $this->finish($s, 'failed', 'the broker refused the order: ' . $order->message, null, $pos->id);
            return;
        }
        $this->sync->sync($order);
        $this->finish($s, 'done', null, 'entry', $pos->id);
    }

    /**
     * Extra buys for what we hold, checked every minute by LiveTick. The 1st extra buy is when the price falls to the 5-minute
     * 20 SMA (and that is under the last buy); every later one is when it is add_drop_pct % under the LAST buy. Every one only while the
     * price is under the day's VWAP. Limits apply as to any buy.
     * @return string[] what was placed
     */
    public function checkAverages(array $cfg): array
    {
        $placed = [];
        $now = Clock::now();
        if (!$cfg['enabled'] || $cfg['add_amount'] <= 0 || $cfg['max_adds'] <= 0) return $placed;
        if (!$now->isWeekday() || $now->format('H:i') < $cfg['entry_from'] || $now->format('H:i') > $cfg['entry_until']) return $placed;

        $positions = LivePosition::where('status', 'open')->where('qty', '>', 0)->where('adds', '<', $cfg['max_adds'])->whereNull('exit_requested_at')->where('mode', $this->broker->mode())->orderBy('id')->get()
            ->filter(fn ($p) => !LiveOrder::where('position_id', $p->id)->whereIn('status', ['sending', 'open'])->exists());
        if ($positions->isEmpty()) return $placed;

        $prices = $this->broker->ltp($positions->pluck('symbol')->unique()->values()->all());

        foreach ($positions as $pos) {
            $ltp = $prices[$pos->symbol] ?? null;
            if (!$ltp) continue;

            $buy = LiveOrder::where('position_id', $pos->id)->where('side', 'buy')->where('filled_qty', '>', 0)->orderByDesc('id')->first();
            $lastBuy = $buy ? (float) ($buy->filled_price ?: $buy->price) : (float) $pos->avg_price;
            if ($ltp >= $lastBuy) continue;   // not below what we last paid: nothing to average down

            $candles = null;
            if ((int) $pos->adds === 0) {
                $candles = $this->broker->recentCandles($pos->symbol, 80);
                if (count($candles) < 20) continue;
                $level = array_sum(array_column(array_slice($candles, -20), 4)) / 20;
                if ($level >= $lastBuy) continue;
                $why = '5-min 20 SMA ₹' . round($level, 2);
            } else {
                $level = $lastBuy * (1 - $cfg['add_drop_pct'] / 100);
                $why = $cfg['add_drop_pct'] . '% under the last buy ₹' . round($lastBuy, 2);
            }
            if ($ltp > $level) continue;

            // only below the day's VWAP (today's completed 5-minute candles)
            $candles ??= $this->broker->recentCandles($pos->symbol, 80);
            $vwap = self::vwap($candles, $now->toDateString());
            if ($vwap === null || $ltp >= $vwap) continue;
            if ($this->limitReason($cfg, (float) $cfg['add_amount'], false)) continue;

            [$type, $price] = $this->buyOrder($cfg, $ltp);
            $qty = (int) floor($cfg['add_amount'] / $price);
            if ($qty < 1) continue;

            $order = $this->send(null, $pos, 'buy', 'average', $qty, $type, $price);
            $placed[] = $pos->symbol . ' average #' . ($pos->adds + 1) . ' (' . $why . ')' . ($order->status === 'rejected' ? ' (refused: ' . $order->message . ')' : '');
            if ($order->status !== 'rejected') $this->sync->sync($order);
        }
        return $placed;
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** The order type and the price to ask: a limit a little above the live price (so it fills), or market. */
    private function buyOrder(array $cfg, float $ltp): array
    {
        if ($cfg['order_type'] === 'market') return ['market', round($ltp, 2)];
        return ['limit', self::tick($ltp * (1 + $cfg['limit_buffer_pct'] / 100), true)];
    }

    /** Round to the 0.05 tick: up for a buy, down for a sell. */
    public static function tick(float $price, bool $up): float
    {
        $t = $up ? ceil(round($price / 0.05, 6)) : floor(round($price / 0.05, 6));
        return round($t * 0.05, 2);
    }

    /** The position-count and money limits. @return string|null the reason a NEW buy of $amount may not go ahead */
    private function limitReason(array $cfg, float $amount, bool $newPosition): ?string
    {
        if ($newPosition && LivePosition::whereIn('status', ['pending', 'open'])->count() >= $cfg['max_open_positions']) {
            return 'limit: already ' . $cfg['max_open_positions'] . ' open positions';
        }

        // money held, plus buys still waiting to fill
        $held = (float) LivePosition::whereIn('status', ['pending', 'open'])->sum('invested');
        foreach (LiveOrder::where('side', 'buy')->whereIn('status', ['sending', 'open'])->get() as $o) $held += ($o->qty - $o->filled_qty) * (float) $o->price;
        if ($held + $amount > $cfg['max_capital_total'] + 0.01) return 'limit: ₹' . number_format($held + $amount) . ' would be over the ₹' . number_format($cfg['max_capital_total']) . ' total';

        $today = 0.0;
        foreach (LiveOrder::where('side', 'buy')->where('placed_at', '>=', Clock::now()->toDateString() . ' 00:00:00')->get() as $o) {
            if (in_array($o->status, ['sending', 'open', 'complete'], true)) $today += $o->qty * (float) ($o->filled_price ?: $o->price);
            elseif ($o->filled_qty > 0) $today += $o->filled_qty * (float) $o->filled_price;
        }
        if ($today + $amount > $cfg['max_new_capital_per_day'] + 0.01) return 'limit: ₹' . number_format($today + $amount) . ' of new buys today would be over ₹' . number_format($cfg['max_new_capital_per_day']);

        return null;
    }

    /** Write the order down, send it, record the answer. */
    public function send(LiveSignal|null $s, LivePosition $pos, string $side, string $kind, int $qty, string $type, float $price): LiveOrder
    {
        $o = LiveOrder::create(['position_id' => $pos->id, 'signal_id' => $s?->id, 'side' => $side, 'kind' => $kind, 'symbol' => $pos->symbol, 'qty' => $qty,
            'order_type' => $type, 'price' => $price, 'status' => 'sending', 'mode' => $this->broker->mode(), 'placed_at' => Clock::now()->toDateTimeString()]);

        $r = $this->broker->place($side, $pos->symbol, $qty, $type, $type === 'limit' ? $price : null);
        $o->update($r['ok']
            ? ['status' => 'open', 'broker_order_id' => $r['id'], 'message' => $r['message'], 'updated_at' => Clock::now()->toDateTimeString()]
            : ['status' => 'rejected', 'message' => $r['message'], 'updated_at' => Clock::now()->toDateTimeString()]);

        return $o->fresh();
    }

    /** The session's VWAP from 5-minute candles ([datetime, o, h, l, c, v]): typical price weighted by volume, for one date. */
    public static function vwap(array $candles, string $date): ?float
    {
        $pv = 0.0; $v = 0.0;
        foreach ($candles as $c) {
            if (substr($c[0], 0, 10) !== $date) continue;
            $pv += (($c[2] + $c[3] + $c[4]) / 3) * $c[5];
            $v  += $c[5];
        }
        return $v > 0 ? $pv / $v : null;
    }

    /** Words only funds carry. A company called "Sky Gold" must not match. */
    public static function looksLikeEtf(string $symbol, ?string $name): bool
    {
        return (bool) preg_match('/\b(ETF|BEES|FUND)\b/', strtoupper((string) $name)) || (bool) preg_match('/(BEES|ETF)$/', strtoupper($symbol));
    }
}
