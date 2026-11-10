<?php

namespace App\Services\Scanner;

/**
 * Replays the scanner's signals on stored 5-minute candles under one set of rules, and reports what it would have made.
 * Pure: it never touches the database — candles and signals are handed in — so it can be tested on hand-made candles.
 *
 * A signal is known at the CLOSE of the candle it is stamped with (the stamp is the candle's start), so by default the entry
 * is that candle's close price.
 *
 * Candle row  = [datetime 'Y-m-d H:i:s', open, high, low, close, volume]   (5-minute)
 * Daily row   = [date 'Y-m-d', open, high, low, close, volume]
 */
class Backtester
{
    const T = 0, O = 1, H = 2, L = 3, C = 4, V = 5;

    const DEFAULTS = [
        // which set of exit / averaging rules
        'strategy'        => 'manual',     // 'manual' = what we did by hand (below) | 'rules' = the SMA / VWAP / hold-days rules the live engine runs
        // the MANUAL strategy (strategy = manual): buy on the signal; average at -5 / -10 / -15 % of the FIRST buy price; a trade that is in
        // profit at the end of the day is sold there (a trailing stop protects it once it is +5 %); a trade that is not goes into a second
        // day, where it is sold as soon as the price is back to its average price (cost to cost), or at the stop 10 % under it
        'm_add_amount'    => 10000,        // ₹ per extra buy
        'm_max_adds'      => 3,            // extra buys at -5 %, -10 %, -15 % …
        'm_add_step_pct'  => 5,            // …each this many % lower than the FIRST buy price
        'm_stop_pct'      => 10,           // stop: this % under the average price
        'm_target_pct'    => 0,            // a trade held over a night is sold when the price reaches its average price plus this % (0 = cost to cost)
        'm_trail_start_pct' => 5,          // a same-day trade that reaches this % profit gets a trailing stop…
        'm_trail_pct'     => 5,            // …this % under its highest price
        'm_max_hold_days' => 5,            // a trade still open after this many trading days is closed at that day's close (our candles only reach about 10 days past a signal)
        'm_carry_winners' => false,        // true = a trade that closes the day +5 % or more keeps running with the trailing stop instead of being sold at the close
        // entry
        'first_amount'    => 10000,        // ₹ put in at the first signal of a stock
        'add_amount'      => 0,            // ₹ per extra buy (0 = never average)
        'max_adds'        => 3,            // at most this many extra buys per position: the 1st when the price falls to the 5-minute 20 SMA, the rest each add_drop_pct % under the last buy
        'add_drop_pct'    => 3,            // % under the LAST buy price for the 2nd, 3rd … extra buys
        'add_below_vwap'  => true,         // an extra buy only while the price is under the day's VWAP
        'entry_price'     => 'close',      // 'close' of the signal candle | 'next_open' | 'trigger' = the price the alert itself carried (live feed; falls back to the close)
        'entry_from'      => '09:15',      // only signals stamped within [entry_from, entry_until] are traded
        'entry_until'     => '15:15',
        'after_signals'   => 1,            // trade the Nth signal of the day for a stock (1 = the first)
        'green_candle'    => true,         // only when the signal candle has CLOSED green (close > open): we trade on closed candles only
        // exit
        'hold_days'       => 0,            // 0 = sell the same day; 1 = the next trading day; ...
        'exit_at'         => 'close',      // 'close' | 'open' of the exit day (open needs hold_days ≥ 1)
        'target_pct'      => null,         // sell when up this % on the average price
        'stop_pct'        => null,         // sell when down this %
        'slippage_pct'    => 0.1,          // adverse price move on every fill, % (illiquid stocks do not fill at the print)
        // filters
        'caps'            => ['Largecap', 'Midcap', 'Smallcap'],
        'exclude_etf'     => true,
        'min_price'       => 20,           // average close of the previous sessions
        'min_turnover'    => 10000000,     // average ₹ traded per day over the previous sessions (₹1 crore)
        'liquidity_days'  => 20,
        'min_day_change'  => null,         // % move from the previous close at the signal, lower bound
        'max_day_change'  => null,         // upper bound
        'above_vwap'      => false,        // only when the signal candle closes above the day's VWAP
    ];

    private array $p;
    private array $liq = [];     // cache: liquidity per symbol|date

    public function __construct(array $params = [])
    {
        $this->p = self::normalize($params);
    }

    public static function normalize(array $in): array
    {
        $d = self::DEFAULTS;
        $p = array_merge($d, array_intersect_key($in, $d));

        $num  = fn ($v, $def, $min = 0, $max = 1e12) => ($v === null || $v === '' || !is_numeric($v)) ? $def : max($min, min($max, (float) $v));
        $opt  = fn ($v, $min, $max) => ($v === null || $v === '' || !is_numeric($v)) ? null : max($min, min($max, (float) $v));
        $time = fn ($v, $def) => (is_string($v) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v)) ? sprintf('%02d:%s', (int) explode(':', $v)[0], explode(':', $v)[1]) : $def;

        $p['strategy']          = in_array($p['strategy'], ['manual', 'rules'], true) ? $p['strategy'] : 'manual';
        $p['m_add_amount']      = $num($p['m_add_amount'], 10000, 0);
        $p['m_max_adds']        = (int) $num($p['m_max_adds'], 3, 0, 10);
        $p['m_add_step_pct']    = $num($p['m_add_step_pct'], 5, 0.5, 50);
        $p['m_stop_pct']        = $num($p['m_stop_pct'], 10, 0.5, 99);
        $p['m_target_pct']      = $num($p['m_target_pct'], 0, 0, 1000);
        $p['m_trail_start_pct'] = $num($p['m_trail_start_pct'], 5, 0.5, 1000);
        $p['m_trail_pct']       = $num($p['m_trail_pct'], 5, 0.5, 50);
        $p['m_max_hold_days']   = (int) $num($p['m_max_hold_days'], 5, 1, 30);
        $p['m_carry_winners']   = filter_var($p['m_carry_winners'], FILTER_VALIDATE_BOOLEAN);
        $p['first_amount']   = $num($p['first_amount'], $d['first_amount'], 0);
        $p['add_amount']     = $num($p['add_amount'], 0, 0);
        $p['max_adds']       = (int) $num($p['max_adds'], 3, 0, 50);
        $p['add_drop_pct']   = $num($p['add_drop_pct'], 3, 0.1, 50);
        $p['entry_price']    = in_array($p['entry_price'], ['close', 'next_open', 'trigger'], true) ? $p['entry_price'] : 'close';
        $p['entry_from']     = $time($p['entry_from'], $d['entry_from']);
        $p['entry_until']    = $time($p['entry_until'], $d['entry_until']);
        $p['after_signals']  = (int) $num($p['after_signals'], 1, 1, 100);
        $p['hold_days']      = (int) $num($p['hold_days'], 0, 0, 30);
        $p['exit_at']        = ($p['exit_at'] === 'open' && $p['hold_days'] >= 1) ? 'open' : 'close';
        $p['target_pct']     = $opt($p['target_pct'], 0.01, 1000);
        $p['stop_pct']       = $opt($p['stop_pct'], 0.01, 99);
        $p['slippage_pct']   = $num($p['slippage_pct'], 0.1, 0, 10);
        $p['caps']           = array_values(array_intersect((array) $p['caps'], ['Largecap', 'Midcap', 'Smallcap'])) ?: [];
        $p['exclude_etf']    = filter_var($p['exclude_etf'], FILTER_VALIDATE_BOOLEAN);
        $p['min_price']      = $num($p['min_price'], 0, 0);
        $p['min_turnover']   = $num($p['min_turnover'], 0, 0);
        $p['liquidity_days'] = (int) $num($p['liquidity_days'], 20, 1, 250);
        $p['min_day_change'] = $opt($p['min_day_change'], -100, 1000);
        $p['max_day_change'] = $opt($p['max_day_change'], -100, 1000);
        $p['above_vwap']     = filter_var($p['above_vwap'], FILTER_VALIDATE_BOOLEAN);
        $p['green_candle']   = filter_var($p['green_candle'], FILTER_VALIDATE_BOOLEAN);
        $p['add_below_vwap'] = filter_var($p['add_below_vwap'], FILTER_VALIDATE_BOOLEAN);

        return $p;
    }

    public function params(): array { return $this->p; }

    /** The 5-minute candle a timestamp falls in (an alert at 10:43 belongs to the candle that starts 10:40). */
    public static function candleKey(string $at): string
    {
        $m = (int) substr($at, 14, 2);
        return substr($at, 0, 14) . sprintf('%02d', $m - $m % 5) . ':00';
    }

    // ─── per-symbol preparation ──────────────────────────────────────────────

    private function prep(string $symbol, array $candles): array
    {
        $days = [];      // date → [first idx, last idx]
        foreach ($candles as $i => $c) {
            $d = substr($c[self::T], 0, 10);
            if (!isset($days[$d])) $days[$d] = [$i, $i]; else $days[$d][1] = $i;
        }
        $list = array_keys($days);
        $byTs = [];
        foreach ($candles as $i => $c) $byTs[$c[self::T]] = $i;

        return ['c' => $candles, 'days' => $days, 'list' => $list, 'pos' => array_flip($list), 'ts' => $byTs, 'vwap' => null];
    }

    /** VWAP of the session up to and including each candle (typical price weighted by volume). */
    private function vwap(array &$s, int $idx): ?float
    {
        if ($s['vwap'] === null) {
            $s['vwap'] = [];
            $day = null; $pv = 0.0; $v = 0.0;
            foreach ($s['c'] as $i => $c) {
                $d = substr($c[self::T], 0, 10);
                if ($d !== $day) { $day = $d; $pv = 0.0; $v = 0.0; }
                $pv += (($c[self::H] + $c[self::L] + $c[self::C]) / 3) * $c[self::V];
                $v  += $c[self::V];
                $s['vwap'][$i] = $v > 0 ? $pv / $v : null;
            }
        }
        return $s['vwap'][$idx] ?? null;
    }

    // ─── liquidity ───────────────────────────────────────────────────────────

    /** @return array{ok: bool, reason: ?string, prev_close: ?float} for a stock on a date, from the sessions BEFORE it */
    private function liquidity(string $symbol, string $date, array $daily): array
    {
        $key = $symbol . '|' . $date;
        if (isset($this->liq[$key])) return $this->liq[$key];

        $prev = [];
        foreach ($daily as $r) { if ($r[0] < $date) $prev[] = $r; else break; }
        $prevClose = $prev ? (float) end($prev)[self::C] : null;
        $take = array_slice($prev, -$this->p['liquidity_days']);

        // history is only needed when a liquidity rule is switched on
        if ($this->p['min_price'] <= 0 && $this->p['min_turnover'] <= 0) return $this->liq[$key] = ['ok' => true, 'reason' => null, 'prev_close' => $prevClose];
        if (count($take) < 5) return $this->liq[$key] = ['ok' => false, 'reason' => 'no daily history', 'prev_close' => $prevClose];

        $price = array_sum(array_column($take, self::C)) / count($take);
        $turn  = array_sum(array_map(fn ($r) => $r[self::C] * $r[self::V], $take)) / count($take);

        if ($price < $this->p['min_price'])      return $this->liq[$key] = ['ok' => false, 'reason' => 'low price', 'prev_close' => $prevClose];
        if ($turn < $this->p['min_turnover'])    return $this->liq[$key] = ['ok' => false, 'reason' => 'illiquid', 'prev_close' => $prevClose];

        return $this->liq[$key] = ['ok' => true, 'reason' => null, 'prev_close' => $prevClose];
    }

    public static function looksLikeEtf(string $symbol, ?string $name): bool
    {
        $n = strtoupper((string) $name);
        // words that only funds carry ("SKY GOLD LTD" and "NIFTY"-named companies must not match)
        return (bool) preg_match('/\b(ETF|BEES|FUND)\b/', $n) || (bool) preg_match('/(BEES|ETF)$/', strtoupper($symbol));
    }

    // ─── the run ─────────────────────────────────────────────────────────────

    /**
     * @param array[] $signals  [['symbol','signal_at','market_cap','sector'], …]
     * @param array   $candles  symbol → 5-minute rows, ascending
     * @param array   $daily    symbol → daily rows, ascending
     * @param array   $names    symbol → instrument name (for the ETF check)
     */
    public function run(array $signals, array $candles, array $daily, array $names = []): array
    {
        $this->liq = [];
        $bySymbol = [];
        foreach ($signals as $s) $bySymbol[$s['symbol']][] = $s;

        $trades = [];
        $outcome = [];   // symbol|date → ['entered' => bool, 'reason' => ?string]

        foreach ($bySymbol as $symbol => $list) {
            usort($list, fn ($a, $b) => strcmp($a['signal_at'], $b['signal_at']));
            $c = $candles[$symbol] ?? [];

            if (!$c) {
                foreach ($list as $s) { $k = $symbol . '|' . substr($s['signal_at'], 0, 10); $outcome[$k] ??= ['entered' => false, 'reason' => 'no candle data']; }
                continue;
            }
            $s = $this->prep($symbol, $c);
            $d = $daily[$symbol] ?? [];
            $nSeen = [];       // date → signals so far that day
            $busyUntil = null;   // candle index at which the position held in this stock is sold

            foreach ($list as $sig) {
                $date = substr($sig['signal_at'], 0, 10);
                $key  = $symbol . '|' . $date;
                $nSeen[$date] = ($nSeen[$date] ?? 0) + 1;
                $outcome[$key] ??= ['entered' => false, 'reason' => null];
                $fail = function (string $reason) use (&$outcome, $key) { if (!$outcome[$key]['entered'] && $outcome[$key]['reason'] === null) $outcome[$key]['reason'] = $reason; };

                $i = $s['ts'][$sig['signal_at']] ?? $s['ts'][self::candleKey($sig['signal_at'])] ?? null;
                if ($i === null) { $fail('no candle data'); continue; }

                // still holding this stock from an earlier signal: a repeat signal buys nothing (extra buys are price-driven, see play())
                if ($busyUntil !== null && $i < $busyUntil) { $fail('already in the trade'); continue; }

                $hm = substr($sig['signal_at'], 11, 5);
                $inWindow = $hm >= $this->p['entry_from'] && $hm <= $this->p['entry_until'];
                $sig['trigger_price'] = self::sane($sig['trigger_price'] ?? null, $s['c'][$i]);
                $fill = $this->fill($s, $i, $sig);

                // ── a new entry: every filter, in order; the first that fails is the reason
                if ($why = $this->entryReason($sig, $s, $d, $i, $nSeen[$date], $fill, $inWindow, $names)) { $fail($why); continue; }

                $qty = (int) floor($this->p['first_amount'] / $fill['price']);
                if ($qty < 1) { $fail('price above the amount'); continue; }

                $new = ['symbol' => $symbol, 'cap' => $sig['market_cap'] ?? null, 'sector' => $sig['sector'] ?? null, 'firstDay' => substr($s['c'][$fill['idx']][self::T], 0, 10),
                        'tranches' => [['idx' => $fill['idx'], 't' => $fill['at'], 'price' => $fill['price'], 'qty' => $qty]],
                        'scanFrom' => $fill['scanFrom'], 'firstScan' => $fill['scanFrom'], 'adds' => 0, 'signalAt' => $sig['signal_at'], 'origSignalAt' => $sig['orig_signal_at'] ?? null, 'signals' => $nSeen[$date]];

                ['pos' => $played, 'x' => $x] = $this->p['strategy'] === 'manual' ? $this->playManual($new, $s) : $this->play($new, $s);
                if ($x['idx'] <= $fill['idx'] && !$x['open']) { $fail('too late to exit'); continue; }

                $outcome[$key]['entered'] = true;
                $trades[] = $this->close($played, $s, $x, $names);
                $busyUntil = $x['open'] ? PHP_INT_MAX : $x['idx'];
            }
        }


        usort($trades, fn ($a, $b) => strcmp($a['exit_at'], $b['exit_at']) ?: strcmp($a['entry_at'], $b['entry_at']));

        return ['params' => $this->p, 'trades' => $trades] + self::summarise($trades) + ['skips' => self::skips($outcome)];
    }


    /** Why this signal cannot start a new position, or null when it can. */
    private function entryReason(array $sig, array &$s, array $daily, int $i, int $nSeenToday, ?array $fill, bool $inWindow, array $names): ?string
    {
        $symbol = $sig['symbol'];
        $date   = substr($sig['signal_at'], 0, 10);

        $cap = (string) ($sig['market_cap'] ?? '');   // a signal with no market cap on it (some exports) is not excluded by this filter
        if ($cap !== '' && !in_array($cap, $this->p['caps'], true)) return 'market cap';
        if ($this->p['exclude_etf'] && self::looksLikeEtf($symbol, $names[$symbol] ?? null)) return 'ETF';
        $lq = $this->liquidity($symbol, $date, $daily);
        if (!$lq['ok']) return $lq['reason'];
        if (!$inWindow) return 'outside time window';
        if ($nSeenToday < $this->p['after_signals']) return 'waiting for signal ' . $this->p['after_signals'];
        if (!$fill) return 'no candle to fill on';
        if ($this->p['green_candle'] && !($s['c'][$i][self::C] > $s['c'][$i][self::O])) return 'candle not green';

        $closePx = $s['c'][$i][self::C];
        if ($this->p['min_day_change'] !== null || $this->p['max_day_change'] !== null) {
            if (!$lq['prev_close']) return 'no previous close';
            $chg = ($closePx / $lq['prev_close'] - 1) * 100;
            if ($this->p['min_day_change'] !== null && $chg < $this->p['min_day_change']) return 'day change too low';
            if ($this->p['max_day_change'] !== null && $chg > $this->p['max_day_change']) return 'day change too high';
        }
        if ($this->p['above_vwap']) {
            $vw = $this->vwap($s, $i);
            if ($vw === null || $closePx < $vw) return 'below VWAP';
        }
        return null;
    }

    /**
     * What a stock does after the scanner's FIRST valid signal of each day, whatever the position rules (no sizing, no exits):
     * the move to that day's close, and the next trading day's open / high / low / close, all from the entry price.
     * Split by whether the stock finished that day up or down.
     */
    public function behaviour(array $signals, array $candles, array $daily, array $names = []): array
    {
        $this->liq = [];
        $bySymbol = [];
        foreach ($signals as $sg) $bySymbol[$sg['symbol']][] = $sg;

        $events = [];
        foreach ($bySymbol as $symbol => $list) {
            if (empty($candles[$symbol])) continue;
            usort($list, fn ($a, $b) => strcmp($a['signal_at'], $b['signal_at']));
            $s = $this->prep($symbol, $candles[$symbol]);
            $d = $daily[$symbol] ?? [];
            $n = []; $done = [];

            foreach ($list as $sig) {
                $date = substr($sig['signal_at'], 0, 10);
                $n[$date] = ($n[$date] ?? 0) + 1;
                if (isset($done[$date])) continue;
                $i = $s['ts'][$sig['signal_at']] ?? $s['ts'][self::candleKey($sig['signal_at'])] ?? null;
                if ($i === null) continue;
                $hm = substr($sig['signal_at'], 11, 5);
                $sig['trigger_price'] = self::sane($sig['trigger_price'] ?? null, $s['c'][$i]);
                $fill = $this->fill($s, $i, $sig);
                if ($this->entryReason($sig, $s, $d, $i, $n[$date], $fill, $hm >= $this->p['entry_from'] && $hm <= $this->p['entry_until'], $names)) continue;
                $done[$date] = true;

                $entry = $this->p['entry_price'] === 'next_open' ? $s['c'][$fill['idx']][self::O]
                    : ($this->p['entry_price'] === 'trigger' && !empty($sig['trigger_price']) ? (float) $sig['trigger_price'] : $s['c'][$i][self::C]);
                $l = $s['days'][$date][1];
                $eod = $s['c'][$l][self::C];
                $hi = 0; $lo = 1e18;
                for ($j = $fill['scanFrom']; $j <= $l; $j++) { $hi = max($hi, $s['c'][$j][self::H]); $lo = min($lo, $s['c'][$j][self::L]); }
                $ev = ['symbol' => $symbol, 'date' => $date, 'entry' => $entry, 'eod' => $eod,
                       'eod_pct' => ($eod / $entry - 1) * 100, 'high_pct' => $hi > 0 ? ($hi / $entry - 1) * 100 : 0, 'low_pct' => $lo < 1e17 ? ($lo / $entry - 1) * 100 : 0,
                       'next' => null];

                $pos = $s['pos'][$date];
                if (isset($s['list'][$pos + 1])) {
                    [$nf, $nl] = $s['days'][$s['list'][$pos + 1]];
                    $nh = 0; $nlo = 1e18;
                    for ($j = $nf; $j <= $nl; $j++) { $nh = max($nh, $s['c'][$j][self::H]); $nlo = min($nlo, $s['c'][$j][self::L]); }
                    $ev['next'] = ['open_pct' => ($s['c'][$nf][self::O] / $entry - 1) * 100, 'close_pct' => ($s['c'][$nl][self::C] / $entry - 1) * 100,
                                   'high_pct' => ($nh / $entry - 1) * 100, 'low_pct' => ($nlo / $entry - 1) * 100,
                                   'close_vs_eod_pct' => ($s['c'][$nl][self::C] / $eod - 1) * 100, 'open_vs_eod_pct' => ($s['c'][$nf][self::O] / $eod - 1) * 100];
                }
                $events[] = $ev;
            }
        }

        $avg = fn (array $a) => $a ? round(array_sum($a) / count($a), 2) : null;
        $pct = fn (array $a, callable $f) => $a ? round(count(array_filter($a, $f)) / count($a) * 100, 1) : null;
        $group = function (string $label, array $ev) use ($avg, $pct) {
            $withNext = array_values(array_filter($ev, fn ($e) => $e['next']));
            return [
                'label' => $label, 'events' => count($ev), 'with_next_day' => count($withNext),
                'eod_avg' => $avg(array_column($ev, 'eod_pct')), 'eod_win' => $pct($ev, fn ($e) => $e['eod_pct'] > 0),
                'best_avg' => $avg(array_column($ev, 'high_pct')), 'worst_avg' => $avg(array_column($ev, 'low_pct')),
                'next_open_avg' => $avg(array_map(fn ($e) => $e['next']['open_pct'], $withNext)),
                'next_close_avg' => $avg(array_map(fn ($e) => $e['next']['close_pct'], $withNext)),
                'next_close_win' => $pct($withNext, fn ($e) => $e['next']['close_pct'] > 0),
                'next_high_avg' => $avg(array_map(fn ($e) => $e['next']['high_pct'], $withNext)),
                'next_vs_eod_avg' => $avg(array_map(fn ($e) => $e['next']['close_vs_eod_pct'], $withNext)),
                'next_vs_eod_win' => $pct($withNext, fn ($e) => $e['next']['close_vs_eod_pct'] > 0),
                'next_open_vs_eod_avg' => $avg(array_map(fn ($e) => $e['next']['open_vs_eod_pct'], $withNext)),
            ];
        };

        $green = array_values(array_filter($events, fn ($e) => $e['eod_pct'] >= 0));
        $red   = array_values(array_filter($events, fn ($e) => $e['eod_pct'] < 0));

        return ['groups' => [$group('All first signals', $events), $group('Finished the day UP', $green), $group('Finished the day DOWN', $red)], 'events' => count($events)];
    }

    // ─── fills and exits ─────────────────────────────────────────────────────

    /**
     * An alert's own price is only used when it agrees with the candle it fell in. Kite's history is adjusted for splits and
     * bonuses, an old alert is not (a ₹2,120 alert against a ₹1,080 candle is a 2-for-1 split), so a price outside the
     * candle's range by more than 3 % is dropped and the candle's close is used instead.
     */
    public static function sane($price, array $candle): ?float
    {
        if (!$price) return null;
        $price = (float) $price;
        return ($price >= $candle[self::L] * 0.97 && $price <= $candle[self::H] * 1.03) ? $price : null;
    }

    /** Where a signal on candle $i is filled. @return array{idx: int, price: float, scanFrom: int, at: string}|null */
    private function fill(array $s, int $i, array $sig = []): ?array
    {
        $slip = $this->p['slippage_pct'] / 100;
        // the price the alert carried, at the minute it fired: what you could have bought at (scanning for exits starts after this candle)
        if ($this->p['entry_price'] === 'trigger' && !empty($sig['trigger_price'])) {
            return ['idx' => $i, 'price' => round((float) $sig['trigger_price'] * (1 + $slip), 2), 'scanFrom' => $i + 1, 'at' => $sig['signal_at']];
        }
        if ($this->p['entry_price'] === 'next_open') {
            $j = $i + 1;
            if (!isset($s['c'][$j]) || substr($s['c'][$j][self::T], 0, 10) !== substr($s['c'][$i][self::T], 0, 10)) return null;   // the session is over
            return ['idx' => $j, 'price' => round($s['c'][$j][self::O] * (1 + $slip), 2), 'scanFrom' => $j, 'at' => $s['c'][$j][self::T]];
        }
        // known at the candle's close: the order goes in as it ends
        return ['idx' => $i, 'price' => round($s['c'][$i][self::C] * (1 + $slip), 2), 'scanFrom' => $i + 1, 'at' => date('Y-m-d H:i:s', strtotime($s['c'][$i][self::T]) + 300)];
    }

    private function avg(array $pos): float
    {
        $q = 0; $v = 0.0;
        foreach ($pos['tranches'] as $t) { $q += $t['qty']; $v += $t['qty'] * $t['price']; }
        return $v / $q;
    }

    /**
     * Plays one position forward candle by candle, from the first buy to the sale:
     *   - stop / target are measured on the average price at that moment (an extra buy moves them);
     *   - extra buys are PRICE-driven, never signal-driven: the 1st when the price falls to the 5-minute 20 SMA (and that is
     *     under the last buy), each later one when it falls add_drop_pct % under the LAST buy. One per candle, a limit at that
     *     level (a gap through it fills at the open), only inside the buying window and never on the exit candle.
     *   - an exit is checked before an extra buy on the same candle (the order inside a candle is unknown: the cautious reading).
     * @return array{pos: array, x: array{idx: int, price: float, reason: string, open: bool}}   open = the data ran out before the planned exit
     */
    private function play(array $pos, array &$s): array
    {
        $c = $s['c'];
        $slip = $this->p['slippage_pct'] / 100;

        // the planned (time) exit
        $dp = $s['pos'][$pos['firstDay']] + $this->p['hold_days'];
        $timeIdx = null;
        if (isset($s['list'][$dp])) {
            [$first, $last] = $s['days'][$s['list'][$dp]];
            $timeIdx = $this->p['exit_at'] === 'open' ? $first : $last;
        }
        $end = $timeIdx ?? (count($c) - 1);
        // an exit at the next open happens before that candle trades: only earlier candles can trigger a stop / target
        $scanTo = ($timeIdx !== null && $this->p['exit_at'] === 'open') ? $end - 1 : $end;
        $canAdd = $this->p['add_amount'] > 0 && $this->p['max_adds'] > 0;

        for ($j = $pos['scanFrom']; $j <= $scanTo; $j++) {
            $o = $c[$j][self::O];
            $avg = $this->avg($pos);
            $stop   = $this->p['stop_pct']   !== null ? $avg * (1 - $this->p['stop_pct'] / 100)   : null;
            $target = $this->p['target_pct'] !== null ? $avg * (1 + $this->p['target_pct'] / 100) : null;
            if ($stop !== null && $c[$j][self::L] <= $stop)       return ['pos' => $pos, 'x' => ['idx' => $j, 'price' => min($o, $stop), 'reason' => 'stop', 'open' => false]];
            if ($target !== null && $c[$j][self::H] >= $target)   return ['pos' => $pos, 'x' => ['idx' => $j, 'price' => max($o, $target), 'reason' => 'target', 'open' => false]];

            if (!$canAdd || $pos['adds'] >= $this->p['max_adds'] || $j >= $end) continue;
            $hm = substr($c[$j][self::T], 11, 5);
            if ($hm < $this->p['entry_from'] || $hm > $this->p['entry_until']) continue;

            $lastBuy = end($pos['tranches'])['price'];
            if ($pos['adds'] === 0) {
                if ($j < 20) continue;
                $sum = 0.0;
                for ($k = $j - 20; $k < $j; $k++) $sum += $c[$k][self::C];
                $level = $sum / 20;
                if ($level >= $lastBuy) continue;   // the average is not under what we paid: nothing to average down to
            } else {
                $level = $lastBuy * (1 - $this->p['add_drop_pct'] / 100);
            }
            if ($c[$j][self::L] > $level) continue;

            $price = round(min($o, $level) * (1 + $slip), 2);
            if ($this->p['add_below_vwap']) {
                // the VWAP known when this candle opens: the session so far, up to the previous candle (none on the first candle of a day)
                $vw = $j > 0 && substr($c[$j - 1][self::T], 0, 10) === substr($c[$j][self::T], 0, 10) ? $this->vwap($s, $j - 1) : null;
                if ($vw === null || $price >= $vw) continue;
            }
            $qty = (int) floor($this->p['add_amount'] / $price);
            if ($qty < 1) continue;
            $pos['tranches'][] = ['idx' => $j, 't' => $c[$j][self::T], 'price' => $price, 'qty' => $qty];
            $pos['adds']++;
            $pos['scanFrom'] = $j + 1;
        }

        if ($timeIdx === null) {
            return ['pos' => $pos, 'x' => ['idx' => count($c) - 1, 'price' => $c[count($c) - 1][self::C], 'reason' => 'open', 'open' => true]];
        }
        return ['pos' => $pos, 'x' => ['idx' => $timeIdx, 'price' => $this->p['exit_at'] === 'open' ? $c[$timeIdx][self::O] : $c[$timeIdx][self::C],
                'reason' => $pos['firstDay'] === substr($c[$timeIdx][self::T], 0, 10) ? 'close' : ($this->p['exit_at'] === 'open' ? 'next open' : 'close'), 'open' => false]];
    }

    /**
     * The MANUAL strategy, candle by candle from the first buy (see DEFAULTS):
     *   - stop: the low reaches the average price less m_stop_pct → sold there (a gap down fills at the open);
     *   - a trade held over a night (it was not in profit at a session close): sold when the high reaches its average price plus
     *     m_target_pct (0 = cost to cost; a gap above fills at the open);
     *   - any other trade (the first day, averaged or not): once an earlier candle's high was m_trail_start_pct over the average, a trailing stop m_trail_pct under the highest price;
     *   - extra buys: the low reaches first buy × (1 − step × n) for n = 1 … m_max_adds, each a limit at that level (a gap fills at the open);
     *   - at the last candle of a session: in profit (close over the average) → sold at the close (unless m_carry_winners and it is up the trail-start %);
     *     not in profit → held over: from the next session it is in the "held over" group above;
     *   - after m_max_hold_days trading days → sold at that day's close; the data ending first leaves it "open".
     * An exit is checked before an extra buy on the same candle, and a candle's own high never feeds its own trailing stop (the order inside a candle is unknown).
     * @return array{pos: array, x: array{idx: int, price: float, reason: string, open: bool}}
     */
    private function playManual(array $pos, array &$s): array
    {
        $c = $s['c'];
        $slip = $this->p['slippage_pct'] / 100;
        $first = $pos['tranches'][0]['price'];

        $dp = $s['pos'][$pos['firstDay']] + $this->p['m_max_hold_days'];
        $capIdx = isset($s['list'][$dp]) ? $s['days'][$s['list'][$dp]][1] : null;
        $end = $capIdx ?? (count($c) - 1);

        $peak = null;
        $heldOver = false;   // was not in profit at some session close
        $exit = function (int $j, float $price, string $reason) use (&$pos) { return ['pos' => $pos, 'x' => ['idx' => $j, 'price' => $price, 'reason' => $reason, 'open' => false]]; };

        for ($j = $pos['scanFrom']; $j <= $end; $j++) {
            [$o, $h, $l, $cl] = [$c[$j][self::O], $c[$j][self::H], $c[$j][self::L], $c[$j][self::C]];
            $avg = $this->avg($pos);
            $held = $heldOver;

            $stop = $avg * (1 - $this->p['m_stop_pct'] / 100);
            if ($l <= $stop) return $exit($j, min($o, $stop), 'stop');

            if ($held) {
                $target = $avg * (1 + $this->p['m_target_pct'] / 100);
                if ($h >= $target) return $exit($j, max($o, $target), 'target');
            } else {
                if ($peak !== null && $peak >= $avg * (1 + $this->p['m_trail_start_pct'] / 100)) {
                    $trail = $peak * (1 - $this->p['m_trail_pct'] / 100);
                    if ($l <= $trail) return $exit($j, min($o, $trail), 'trail');
                }
                $peak = max($peak ?? $h, $h);
            }

            // extra buys at -step, -2 step … of the FIRST buy price (a fast fall can fill several in one candle)
            while ($j < $end && $this->p['m_add_amount'] > 0 && $pos['adds'] < $this->p['m_max_adds']) {
                $level = $first * (1 - $this->p['m_add_step_pct'] * ($pos['adds'] + 1) / 100);
                if ($l > $level) break;
                $price = round(min($o, $level) * (1 + $slip), 2);
                $qty = (int) floor($this->p['m_add_amount'] / $price);
                if ($qty < 1) break;
                $pos['tranches'][] = ['idx' => $j, 't' => $c[$j][self::T], 'price' => $price, 'qty' => $qty];
                $pos['adds']++;
            }

            // the end of the session
            $date = substr($c[$j][self::T], 0, 10);
            if ($j === $s['days'][$date][1]) {
                $avg = $this->avg($pos);
                if ($cl > $avg) {
                    $runs = $this->p['m_carry_winners'] && !$heldOver && $cl >= $avg * (1 + $this->p['m_trail_start_pct'] / 100);
                    if (!$runs) return $exit($j, $cl, 'day end');
                } else {
                    $heldOver = true;
                }
            }
            if ($j === $capIdx) return $exit($j, $cl, 'time');
        }

        $last = count($c) - 1;
        return ['pos' => $pos, 'x' => ['idx' => $last, 'price' => $c[$last][self::C], 'reason' => 'open', 'open' => true]];
    }

    private function close(array $pos, array $s, array $x, array $names): array
    {
        $c = $s['c'];
        $slip = $this->p['slippage_pct'] / 100;
        $exitPx = round($x['price'] * (1 - $slip), 2);

        $qty = 0; $cost = 0.0; $orders = [];
        foreach ($pos['tranches'] as $t) { $qty += $t['qty']; $cost += $t['qty'] * $t['price']; $orders[] = $t['qty'] * $t['price']; }
        $avg = $cost / $qty;
        $sell = $qty * $exitPx;
        $gross = $sell - $cost;
        $sameDay = $this->p['strategy'] === 'manual' ? substr($c[$x['idx']][self::T], 0, 10) === $pos['firstDay'] : $this->p['hold_days'] === 0;
        $charges = Charges::equity($orders, $sell, $sameDay);

        // how far the trade went for / against us while we held it
        $first = $pos['firstScan'];
        $hi = $avg; $lo = $avg;
        for ($j = $first; $j <= $x['idx']; $j++) { $hi = max($hi, $c[$j][self::H]); $lo = min($lo, $c[$j][self::L]); }

        $exitTs = $c[$x['idx']][self::T];
        // an exit at the open is stamped at the candle's start; at the close, at the candle's end (5 minutes on)
        $exitStamp = ($x['reason'] === 'next open') ? $exitTs : date('Y-m-d H:i:s', strtotime($exitTs) + 300);

        return [
            'symbol' => $pos['symbol'], 'cap' => $pos['cap'], 'sector' => $pos['sector'],
            'signal_at' => $pos['signalAt'], 'orig_signal_at' => $pos['origSignalAt'] ?? null, 'signals' => $pos['signals'],
            'entry_at' => $pos['tranches'][0]['t'], 'exit_at' => $exitStamp, 'exit_date' => substr($x['reason'] === 'open' ? $exitTs : $exitStamp, 0, 10),
            'exit_reason' => $x['reason'], 'held_days' => (int) ((strtotime(substr($exitTs, 0, 10)) - strtotime($pos['firstDay'])) / 86400),
            'tranches' => array_map(fn ($t) => ['at' => $t['t'], 'price' => $t['price'], 'qty' => $t['qty']], $pos['tranches']),
            'qty' => $qty, 'avg_price' => round($avg, 2), 'exit_price' => $exitPx, 'invested' => round($cost, 2),
            'gross' => round($gross, 2), 'charges' => $charges, 'net' => round($gross - $charges, 2),
            'net_pct' => $cost > 0 ? round(($gross - $charges) / $cost * 100, 2) : 0.0,
            'mfe_pct' => round(($hi / $avg - 1) * 100, 2), 'mae_pct' => round(($lo / $avg - 1) * 100, 2),
            'open' => $x['open'],
        ];
    }

    // ─── reporting ───────────────────────────────────────────────────────────

    public static function summarise(array $trades): array
    {
        $days = [];
        foreach ($trades as $t) {
            $d = $t['exit_date'];
            $days[$d] ??= ['date' => $d, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'invested' => 0.0, 'gross' => 0.0, 'charges' => 0.0, 'net' => 0.0];
            $days[$d]['trades']++;
            $days[$d][$t['net'] > 0 ? 'wins' : 'losses']++;
            $days[$d]['invested'] += $t['invested']; $days[$d]['gross'] += $t['gross']; $days[$d]['charges'] += $t['charges']; $days[$d]['net'] += $t['net'];
        }
        ksort($days);

        $cum = 0.0; $peak = 0.0; $maxDd = 0.0;
        foreach ($days as &$d) {
            foreach (['invested', 'gross', 'charges', 'net'] as $k) $d[$k] = round($d[$k], 2);
            $cum += $d['net']; $peak = max($peak, $cum); $maxDd = max($maxDd, $peak - $cum);
            $d['cumulative'] = round($cum, 2);
        }
        unset($d);

        $n = count($trades);
        $win = array_filter($trades, fn ($t) => $t['net'] > 0);
        $lossSum = abs(array_sum(array_map(fn ($t) => $t['net'], array_filter($trades, fn ($t) => $t['net'] <= 0))));
        $winSum = array_sum(array_map(fn ($t) => $t['net'], $win));
        $invested = array_sum(array_column($trades, 'invested'));
        $net = round(array_sum(array_column($trades, 'net')), 2);

        $group = function (callable $keyOf) use ($trades) {
            $g = [];
            foreach ($trades as $t) {
                $k = $keyOf($t);
                $g[$k] ??= ['key' => $k, 'trades' => 0, 'wins' => 0, 'net' => 0.0, 'invested' => 0.0];
                $g[$k]['trades']++; $g[$k]['wins'] += $t['net'] > 0 ? 1 : 0; $g[$k]['net'] += $t['net']; $g[$k]['invested'] += $t['invested'];
            }
            foreach ($g as &$r) { $r['net'] = round($r['net'], 2); $r['win_rate'] = $r['trades'] ? round($r['wins'] / $r['trades'] * 100, 1) : 0; $r['avg_pct'] = $r['invested'] ? round($r['net'] / $r['invested'] * 100, 2) : 0; $r['invested'] = round($r['invested'], 2); }
            return array_values($g);
        };

        return [
            'days' => array_values($days),
            'summary' => [
                'trades' => $n, 'wins' => count($win), 'losses' => $n - count($win),
                'win_rate' => $n ? round(count($win) / $n * 100, 1) : 0,
                'net' => $net, 'gross' => round(array_sum(array_column($trades, 'gross')), 2), 'charges' => round(array_sum(array_column($trades, 'charges')), 2),
                'invested' => round($invested, 2), 'return_pct' => $invested > 0 ? round($net / $invested * 100, 2) : 0,
                'avg_net' => $n ? round($net / $n, 2) : 0, 'avg_pct' => $n ? round(array_sum(array_column($trades, 'net_pct')) / $n, 2) : 0,
                'best' => $n ? max(array_column($trades, 'net')) : 0, 'worst' => $n ? min(array_column($trades, 'net')) : 0,
                'profit_factor' => $lossSum > 0 ? round($winSum / $lossSum, 2) : null,
                'days' => count($days), 'green_days' => count(array_filter($days, fn ($d) => $d['net'] > 0)),
                'max_drawdown' => round($maxDd, 2), 'peak_capital' => self::peakCapital($trades),
                'open_positions' => count(array_filter($trades, fn ($t) => $t['open'])),
            ],
            'by_cap'  => $group(fn ($t) => $t['cap'] ?: '—'),
            'by_time' => $group(function ($t) {
                $hm = substr($t['signal_at'], 11, 5);
                return $hm <= '09:15' ? '1. Opening candle (9:15)' : ($hm < '10:00' ? '2. 9:20 – 10:00' : ($hm < '12:00' ? '3. 10:00 – 12:00' : '4. After 12:00'));
            }),
        ];
    }

    /** The most money out at once: sweep buys (+) and sells (−) in time order. */
    private static function peakCapital(array $trades): float
    {
        $ev = [];
        foreach ($trades as $t) {
            foreach ($t['tranches'] as $tr) $ev[] = [$tr['at'], 1, $tr['price'] * $tr['qty']];
            $ev[] = [$t['exit_at'], 0, -$t['invested']];   // at the same instant, sells are counted first
        }
        usort($ev, fn ($a, $b) => strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);
        $cur = 0.0; $peak = 0.0;
        foreach ($ev as $e) { $cur += $e[2]; $peak = max($peak, $cur); }
        return round($peak, 2);
    }

    private static function skips(array $outcome): array
    {
        $r = ['entered' => 0];
        foreach ($outcome as $o) {
            if ($o['entered']) { $r['entered']++; continue; }
            $k = $o['reason'] ?? 'already in the trade';
            $r[$k] = ($r[$k] ?? 0) + 1;
        }
        arsort($r);
        return $r;
    }
}
