<?php

namespace App\Services\Live;

use App\Models\AppSetting;
use App\Services\Scanner\SignalQuality;

/**
 * The rules live trading runs on — saved from the "Live Config" page, read by the trading engine.
 *
 * Money is involved, so saving is STRICT: a bad value is refused with a message, never quietly replaced. Reading is tolerant:
 * anything missing falls back to the safe defaults below (trading off, dry-run).
 *
 * This is deliberately its own thing — it knows nothing about the scanner backtest, so changes there cannot reach live trading.
 */
class LiveConfig
{
    const KEY = 'live_config';

    /** The signal-quality thresholds the live engine judges signals with: [min, max]. (The entry window comes from entry_from / entry_until.) */
    const QUALITY_RULES = [
        'max_day_chg' => [0, 100], 'max_from_low' => [0, 100], 'max_vwap_dist' => [0, 100], 'max_rsi' => [1, 100], 'min_rsi' => [0, 99],
        'min_vol_ratio' => [0, 100], 'max_chg_5d' => [0, 1000], 'max_daily_rsi' => [1, 100], 'max_chg_1m' => [0, 1000],
        'd_green_days' => [1, 10], 'd_green_min' => [0, 10],
    ];

    public static function defaults(): array
    {
        return [
            // ── master switches ─────────────────────────────────────────────
            'enabled'        => false,        // false = no new buys or averages (exits of what is held still run)
            'mode'           => 'dry_run',    // 'dry_run' = real prices, simulated orders | 'live' = real orders on Zerodha
            'alert_names'    => ['RISH5-1'],  // only alerts with these names trade
            'max_alert_age_minutes' => 5,     // an alert older than this when it arrives is ignored (replays, delays)
            'watch_all_day' => true,          // ChartInk sends a stock only once, so a signal that is still waiting (Watch, or Can enter with no free slot) is watched until the buying window ends
            'signal_valid_minutes'  => 60,    // …unless watch_all_day is off: then it is dropped this long after its candle closed

            // ── entry ───────────────────────────────────────────────────────
            'first_amount'   => 10000,        // ₹ for the first buy of a stock
            'add_amount'     => 10000,        // ₹ for each averaging buy (0 = never average)
            'max_adds'       => 3,            // the 1st average when the price falls to the 5-minute 20 SMA, each later one add_drop_pct % under the LAST buy
            'add_drop_pct'   => 3,
            'entry_from'     => '09:15',      // new buys / averages only between these times
            'entry_until'    => '15:15',

            // ── exit ────────────────────────────────────────────────────────
            // For now exits are by profit % or by hand (the Exit button). A time exit and a stop loss are there but off (null).
            'hold_days'      => null,         // null = no time exit; 0 = sell the same day; 1 = the next trading day ...
            'exit_time'      => '15:15',      // on the exit day, sell from this time
            'target_pct'     => 10,           // sell when up this % on the average price (null = none)
            'stop_pct'       => null,         // sell when down this % (null = none)

            // ── which stocks ────────────────────────────────────────────────
            'min_price'      => 20,
            'min_turnover'   => 10000000,     // average ₹ traded per day over `liquidity_days` sessions
            'liquidity_days' => 20,
            'exclude_etf'    => true,

            // ── how orders are sent ─────────────────────────────────────────
            'order_type'     => 'limit',      // 'limit' | 'market'  (thin stocks: Zerodha may refuse market orders)
            'limit_buffer_pct' => 0.5,        // a buy limit is this % above the live price (so it fills)
            'exit_buffer_pct'  => 1.0,        // a sell limit is this % below the live price
            'entry_timeout_minutes' => 10,    // an unfilled buy is cancelled after this long
            'exit_timeout_minutes'  => 3,     // an unfilled sell is cancelled and re-tried after this long

            // ── limits ──────────────────────────────────────────────────────
            'max_open_positions'     => 10,
            'max_capital_total'      => 200000,   // ₹ held across all positions
            'max_new_capital_per_day' => 100000,  // ₹ of new buys per day

            // ── signal quality: a signal is Can enter / Watch / Skip by these (see SignalQuality) ──
            'quality' => [
                'max_day_chg' => 8, 'max_from_low' => 6, 'max_vwap_dist' => 3, 'max_rsi' => 75, 'min_rsi' => 45, 'min_vol_ratio' => 1,
                'daily_uptrend' => true, 'd_green_days' => 5, 'd_green_min' => 3, 'max_chg_5d' => 15, 'max_daily_rsi' => 80, 'max_chg_1m' => 60,
            ],
        ];
    }

    /** What is saved, over the defaults. */
    public static function get(): array
    {
        $saved = json_decode((string) AppSetting::value(self::KEY, ''), true);
        $cfg = array_merge(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : []);
        $cfg['alert_names'] = array_values(array_filter((array) $cfg['alert_names'], 'is_string'));
        $cfg['quality'] = array_merge(self::defaults()['quality'], is_array($saved['quality'] ?? null) ? array_intersect_key($saved['quality'], self::defaults()['quality']) : []);
        return $cfg;

    }

    /** The thresholds SignalQuality judges with: the saved ones plus the buying window. */
    public static function qualityParams(array $cfg): array
    {
        return SignalQuality::normalize($cfg['quality'] + ['entry_from' => $cfg['entry_from'], 'entry_until' => $cfg['entry_until']]);
    }

    /**
     * Check a config. @return array{ok: bool, errors: array<string,string>, config: array}
     */
    public static function validate(array $in): array
    {
        $d = self::defaults();
        $errors = [];
        $c = [];

        $bool = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $num = function (string $k, $min, $max, bool $int = false, bool $nullable = false) use ($in, &$errors, &$c) {
            $v = $in[$k] ?? null;
            if (($v === null || $v === '') && $nullable) { $c[$k] = null; return; }
            if ($v === null || $v === '' || !is_numeric($v)) { $errors[$k] = 'Enter a number'; return; }
            $n = (float) $v;
            if ($int && floor($n) != $n) { $errors[$k] = 'Enter a whole number'; return; }
            if ($n < $min || $n > $max) { $errors[$k] = "Must be between {$min} and {$max}"; return; }
            $c[$k] = $int ? (int) $n : $n;
        };
        $time = function (string $k) use ($in, &$errors, &$c) {
            $v = $in[$k] ?? '';
            if (!is_string($v) || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v)) { $errors[$k] = 'Enter a time like 09:15'; return; }
            $c[$k] = sprintf('%02d:%s', (int) explode(':', $v)[0], explode(':', $v)[1]);
        };
        $pick = function (string $k, array $allowed) use ($in, &$errors, &$c) {
            $v = $in[$k] ?? null;
            if (!in_array($v, $allowed, true)) { $errors[$k] = 'Choose one of: ' . implode(', ', $allowed); return; }
            $c[$k] = $v;
        };

        $b = $bool($in['enabled'] ?? null);
        if ($b === null) $errors['enabled'] = 'Choose on or off'; else $c['enabled'] = $b;
        $pick('mode', ['dry_run', 'live']);

        $names = $in['alert_names'] ?? [];
        if (is_string($names)) $names = explode(',', $names);
        $names = array_values(array_unique(array_filter(array_map('trim', (array) $names), fn ($n) => $n !== '')));
        if (!$names) $errors['alert_names'] = 'Enter at least one alert name';
        elseif (array_filter($names, fn ($n) => !preg_match('/^[A-Za-z0-9 _\-\.]{1,100}$/', $n))) $errors['alert_names'] = 'Alert names can use letters, numbers, spaces and - _ .';
        else $c['alert_names'] = $names;
        $num('max_alert_age_minutes', 1, 30, true);
        $b = $bool($in['watch_all_day'] ?? null);
        if ($b === null) $errors['watch_all_day'] = 'Choose whole day or a set time'; else $c['watch_all_day'] = $b;
        $num('signal_valid_minutes', 5, 240, true);

        $num('first_amount', 500, 1000000);
        $num('add_amount', 0, 1000000);
        $num('max_adds', 0, 20, true);
        $num('add_drop_pct', 0.1, 50);
        $time('entry_from'); $time('entry_until');

        $num('hold_days', 0, 10, true, true);
        $time('exit_time');
        $num('target_pct', 0.1, 100, false, true);
        $num('stop_pct', 0.1, 50, false, true);

        $num('min_price', 0, 100000);
        $num('min_turnover', 0, 1e12);
        $num('liquidity_days', 5, 100, true);
        $b = $bool($in['exclude_etf'] ?? null);
        if ($b === null) $errors['exclude_etf'] = 'Choose yes or no'; else $c['exclude_etf'] = $b;

        $pick('order_type', ['limit', 'market']);
        $num('limit_buffer_pct', 0, 5);
        $num('exit_buffer_pct', 0, 10);
        $num('entry_timeout_minutes', 1, 60, true);
        $num('exit_timeout_minutes', 1, 60, true);

        $num('max_open_positions', 1, 100, true);
        $num('max_capital_total', 500, 1e9);
        $num('max_new_capital_per_day', 500, 1e9);

        // the signal-quality thresholds
        $q = is_array($in['quality'] ?? null) ? $in['quality'] : [];
        $c['quality'] = [];
        foreach (self::QUALITY_RULES as $k => [$min, $max]) {
            $v = $q[$k] ?? null;
            if ($v === null || $v === '' || !is_numeric($v)) { $errors['q_' . $k] = 'Enter a number'; continue; }
            if ((float) $v < $min || (float) $v > $max) { $errors['q_' . $k] = "Must be between {$min} and {$max}"; continue; }
            $c['quality'][$k] = (float) $v;
        }
        foreach (['d_green_days', 'd_green_min'] as $k) if (isset($c['quality'][$k])) { if (floor($c['quality'][$k]) != $c['quality'][$k]) $errors['q_' . $k] = 'Enter a whole number'; else $c['quality'][$k] = (int) $c['quality'][$k]; }
        if (isset($c['quality']['d_green_days'], $c['quality']['d_green_min']) && $c['quality']['d_green_min'] > $c['quality']['d_green_days']) $errors['q_d_green_min'] = 'Cannot be more than the number of days looked at';
        $ut = $bool($q['daily_uptrend'] ?? null);
        if ($ut === null) $errors['q_daily_uptrend'] = 'Choose yes or no'; else $c['quality']['daily_uptrend'] = $ut;

        // rules that need two fields to agree
        if (!$errors) {
            if ($c['entry_from'] < '09:15') $errors['entry_from'] = 'The market opens at 09:15';
            if ($c['entry_until'] > '15:25') $errors['entry_until'] = 'Buy before 15:25';
            if ($c['entry_from'] >= $c['entry_until']) $errors['entry_until'] = 'Must be after the start time';
            if ($c['exit_time'] < '09:30' || $c['exit_time'] > '15:25') $errors['exit_time'] = 'Choose a time between 09:30 and 15:25';
            if ($c['hold_days'] === 0 && !isset($errors['entry_until']) && !isset($errors['exit_time']) && $c['entry_until'] >= $c['exit_time']) {
                $errors['entry_until'] = 'When selling the same day, stop buying before the exit time';
            }
            if ($c['max_capital_total'] < $c['first_amount']) $errors['max_capital_total'] = 'Cannot be less than one first buy';
            if ($c['max_new_capital_per_day'] < $c['first_amount']) $errors['max_new_capital_per_day'] = 'Cannot be less than one first buy';
            if ($c['add_amount'] > 0 && $c['max_adds'] === 0) $errors['max_adds'] = 'Averaging is on but allows no extra buys';
        }

        // keep the order of the defaults
        $config = [];
        foreach ($d as $k => $_) if (array_key_exists($k, $c)) $config[$k] = $c[$k];

        return ['ok' => !$errors, 'errors' => $errors, 'config' => $config];
    }

    /** Save. Switching a dry-run setup to LIVE needs `$confirmLive` (the page asks first). @return array{ok: bool, errors: array, config: array} */
    public static function save(array $in, bool $confirmLive = false): array
    {
        $r = self::validate($in);
        if (!$r['ok']) return $r;

        $was = self::get();
        if ($r['config']['mode'] === 'live' && !$confirmLive && ($was['mode'] !== 'live' || !$was['enabled'])) {
            $r['ok'] = false;
            $r['errors']['mode'] = 'Real orders: confirm that you want live mode';
            return $r;
        }

        AppSetting::put(self::KEY, json_encode($r['config']));
        return $r;
    }

    /** The most one stock can cost: the first buy and every allowed average. */
    public static function maxPerStock(array $c): float
    {
        return $c['first_amount'] + $c['add_amount'] * $c['max_adds'];
    }
}
