<?php

namespace App\Services\Live;

use Illuminate\Support\Carbon;
use App\Models\LiveSignal;
use App\Models\LiveSignalCheck;
use App\Services\Live\Brokers\Broker;
use App\Services\Scanner\SignalQuality;

/**
 * Rates a live signal as an ENTRY — the same twelve checks as the Signal Quality tab, run on Zerodha's candles:
 *
 *   enter  (Can enter)  every check passes: the stock is fine and so is the moment
 *   watch               a timing check fails (5-minute candle: not green, run too far, RSI, VWAP, volume…): re-checked on every closed candle
 *   skip                a context check fails (daily / monthly picture): shown as a weak setup — the signal is still watched (it comes only once)
 *   nodata              the candle is not there (yet)
 *
 * Only CLOSED candles are used. On top of that a price-drift guard: if the live price has already run more than DRIFT_PCT above the
 * close of the candle that was judged, the stock is Watch again (the entry the check approved is gone).
 * Every look is written to live_signal_checks, so the page can show how a signal's status changed over time.
 */
class SignalClassifier
{
    const DRIFT_PCT = 1.0;
    const CANDLES = 450;     // about 6 sessions of 5-minute candles (indicator history)
    const SESSIONS = 450;    // daily sessions (about 18 months: enough for the monthly picture)

    public function __construct(private Broker $broker) {}

    /** The 5-minute candle that closed last (its start time): the latest candle a decision may use. */
    public static function latestClosed(?Carbon $now = null): Carbon
    {
        $t = ($now ?? Clock::now())->copy()->subSeconds(SignalProcessor::CLOSE_GRACE_SECONDS);
        $start = $t->copy()->setTime((int) $t->format('H'), (int) $t->format('i') - ((int) $t->format('i') % 5), 0)->subMinutes(5);
        $last = $t->copy()->setTime(15, 25, 0);
        return $start->gt($last) ? $last : $start;
    }

    /**
     * Rate $s at the latest closed candle. @param string $kind 'candle' | 'recheck'
     * @return array{verdict: string, score: ?int, candle_at: ?string, price: ?float, ltp: ?float, fails: string[], note: string, f: ?array}
     */
    public function evaluate(LiveSignal $s, array $cfg, Carbon $target, string $kind = 'candle'): array
    {
        $out = ['verdict' => 'nodata', 'score' => null, 'candle_at' => $target->format('Y-m-d H:i:s'), 'price' => null, 'ltp' => null, 'fails' => [], 'note' => '', 'f' => null, 'row' => null];

        $five = $this->broker->recentCandles($s->symbol, self::CANDLES);
        $S = SignalQuality::prepare($five);
        if (!isset($S['ts'][$out['candle_at']])) { $out['note'] = 'the ' . $target->format('H:i') . ' candle is not available from the broker'; return $out; }

        $daily = $this->broker->dailyHistory($s->symbol, self::SESSIONS);
        $p = LiveConfig::qualityParams($cfg);
        $dcache = [];
        $row = SignalQuality::evaluate(['signal_at' => $out['candle_at'], 'symbol' => $s->symbol, 'market_cap' => $s->market_cap], $S, $daily, $dcache, $p);

        $out['row'] = $row; $out['verdict'] = $row['verdict']; $out['score'] = $row['score']; $out['fails'] = $row['fails']; $out['f'] = $row['f'];
        $out['price'] = $row['f']['price'] ?? null;

        // the live price must still be near the candle that was judged
        $ltp = $this->broker->ltp([$s->symbol])[$s->symbol] ?? null;
        $out['ltp'] = $ltp;
        if ($out['verdict'] === 'enter' && $ltp !== null && $out['price'] && $ltp > $out['price'] * (1 + self::DRIFT_PCT / 100)) {
            $out['verdict'] = 'watch';
            $out['fails'][] = 'drift';
            $out['note'] = 'price ₹' . round($ltp, 2) . ' is already ' . round(($ltp / $out['price'] - 1) * 100, 2) . '% above the ' . $target->format('H:i') . ' close (₹' . round($out['price'], 2) . ')';
            return $out;
        }

        $out['note'] = self::explain($out['verdict'], $out['fails'], $out['f'], $p);
        return $out;
    }

    /** A sentence for a verdict: what failed and by how much. */
    public static function explain(string $verdict, array $fails, ?array $f, array $p): string
    {
        if ($verdict === 'enter') return 'all checks pass';
        if ($verdict === 'nodata') return 'no candle data';
        $label = [];
        foreach (SignalQuality::CHECKS as [$code, , , $short]) $label[$code] = $short;
        $ctx = $f['ctx'] ?? [];
        $detail = [
            'green'    => fn () => 'candle ' . ($f['body_pct'] ?? 0) . '%',
            'day_chg'  => fn () => '+' . $f['day_chg'] . '% today, max ' . $p['max_day_chg'] . '%',
            'from_low' => fn () => '+' . $f['from_low'] . '% off the low, max ' . $p['max_from_low'] . '%',
            'vwap'     => fn () => ($f['vwap_dist'] < 0 ? 'under VWAP ' : '+') . $f['vwap_dist'] . '% from VWAP, max ' . $p['max_vwap_dist'] . '%',
            'rsi_hi'   => fn () => 'RSI ' . round($f['rsi'], 1) . ', max ' . $p['max_rsi'],
            'rsi_lo'   => fn () => 'RSI ' . round($f['rsi'], 1) . ', min ' . $p['min_rsi'],
            'volume'   => fn () => 'volume ' . $f['vol_ratio'] . '× average, min ' . $p['min_vol_ratio'] . '×',
            'window'   => fn () => 'outside ' . $p['entry_from'] . '–' . $p['entry_until'],
            'd_trend'  => fn () => 'daily close ' . ($ctx['d_vs_sma20'] ?? '?') . '% vs 20 SMA, ' . count(array_filter(array_slice($ctx['d_green'] ?? [], -$p['d_green_days']))) . ' of the last ' . min($p['d_green_days'], count($ctx['d_green'] ?? [])) . ' daily candles green (need ' . $p['d_green_min'] . ')',
            'd_chg5'   => fn () => '+' . ($ctx['d_chg5'] ?? '?') . '% in 5 days, max ' . $p['max_chg_5d'] . '%',
            'd_rsi'    => fn () => 'daily RSI ' . round($ctx['d_rsi'] ?? 0, 1) . ', max ' . $p['max_daily_rsi'],
            'm_chg1'   => fn () => '+' . ($ctx['m_chg1'] ?? '?') . '% last month, max ' . $p['max_chg_1m'] . '%',
        ];
        $parts = [];
        foreach ($fails as $code) {
            if (!isset($label[$code])) continue;
            $d = null;
            try { $d = $f ? ($detail[$code])() : null; } catch (\Throwable $e) {}
            $parts[] = $label[$code] . ($d ? ' (' . $d . ')' : '');
        }
        return ($verdict === 'skip' ? 'waiting (daily / monthly picture) — ' : 'waiting — ') . implode('; ', $parts);
    }

    /** Write the look down. @return LiveSignalCheck */
    public static function record(LiveSignal $s, array $r, string $kind): LiveSignalCheck
    {
        $now = Clock::now()->toDateTimeString();
        $f = $r['f'] ?? null;
        $ctx = $f['ctx'] ?? [];
        if ($f) unset($f['ctx']);
        $details = $f ? json_encode(['f' => $f, 'ctx' => array_intersect_key($ctx, array_flip(['d_rsi', 'd_vs_sma20', 'd_chg5', 'd_atr', 'm_chg1', 'm_chg3', 'm_bb'])),
                                      'na' => $r['row']['na'] ?? []]) : null;

        $check = LiveSignalCheck::create([
            'signal_id' => $s->id, 'checked_at' => $now, 'candle_at' => $r['candle_at'], 'kind' => $kind, 'verdict' => $r['verdict'], 'score' => $r['score'],
            'price' => $r['price'], 'ltp' => $r['ltp'], 'fails' => $r['fails'] ? mb_substr(implode(',', $r['fails']), 0, 120) : null,
            'note' => mb_substr((string) $r['note'], 0, 250), 'details' => $details,
        ]);

        $u = ['verdict' => $r['verdict'], 'score' => $r['score'], 'checked_at' => $now, 'checks' => (int) $s->checks + 1];
        if ($r['verdict'] !== 'nodata') $u['checked_candle'] = $r['candle_at'];   // a candle we could not read is looked at again
        $s->update($u);
        return $check;
    }
}
