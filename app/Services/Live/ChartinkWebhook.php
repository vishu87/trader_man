<?php

namespace App\Services\Live;

use Illuminate\Support\Facades\DB;

/**
 * Takes one ChartInk alert call: keeps it exactly as received (raw_data_chartink) and turns each stock in it into a live signal.
 * Quick and free of broker calls, so the webhook answers at once; the trading engine picks the signals up from live_signals.
 *
 * ChartInk posts: stocks "A,B", trigger_prices "1.5,2.5", triggered_at "9:18 am", scan_name, scan_url, alert_name, webhook_url and
 * columns = one object per stock with the scan's columns (symbol, sector, marketcapname, rsi, close …), in the same order as stocks.
 */
class ChartinkWebhook
{
    /** @return array{raw_id: int, signals: int, new: int, duplicates: int, ignored: int} */
    public static function receive(array $input, ?string $ip = null): array
    {
        $now   = Clock::now();
        $cfg   = LiveConfig::get();
        $alert = trim((string) ($input['alert_name'] ?? ''));

        $rawId = DB::table('raw_data_chartink')->insertGetId([
            'alert_name' => $alert !== '' ? mb_substr($alert, 0, 100) : null,
            'raw_data'   => json_encode($input),
            'ip'         => $ip,
            'received_at' => $now->toDateTimeString(),
        ]);

        $stocks = array_values(array_filter(array_map('trim', explode(',', (string) ($input['stocks'] ?? '')))));
        $prices = array_map('trim', explode(',', (string) ($input['trigger_prices'] ?? '')));

        // when it fired: today's date and the time ChartInk gives; if that is missing or in the future, now
        $at = $now->copy();
        $t = \DateTime::createFromFormat('g:i a', strtolower(trim((string) ($input['triggered_at'] ?? ''))));
        if ($t) {
            $at = $now->copy()->setTime((int) $t->format('H'), (int) $t->format('i'), 0);
            if ($at->gt($now->copy()->addMinutes(2))) $at = $now->copy();
        }
        $ageMin = max(0, (int) floor($at->diffInSeconds($now) / 60));

        // the scan's columns for each stock: matched by the symbol in the column when it has one, else by position
        $cols = is_array($input['columns'] ?? null) ? array_values($input['columns']) : [];
        $bySymbol = [];
        foreach ($cols as $c) if (is_array($c) && isset($c['symbol']) && is_string($c['symbol'])) $bySymbol[strtoupper(trim($c['symbol']))] = $c;
        $text = fn ($v, int $n) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $n) : null;
        $num  = fn ($v) => is_numeric($v) ? round((float) $v, 2) : null;
        $scan = $text($input['scan_name'] ?? null, 100);

        $out = ['raw_id' => $rawId, 'signals' => 0, 'new' => 0, 'duplicates' => 0, 'ignored' => 0];

        foreach ($stocks as $i => $symbol) {
            $symbol = strtoupper($symbol);
            if (!preg_match('/^[A-Z0-9&\-\._]{1,50}$/', $symbol) || $alert === '') { $out['ignored']++; continue; }
            $out['signals']++;

            $status = 'new'; $reason = null;
            if (!in_array($alert, $cfg['alert_names'], true)) { $status = 'skipped'; $reason = 'alert "' . $alert . '" is not enabled in Live Config'; }
            elseif ($ageMin > $cfg['max_alert_age_minutes']) { $status = 'skipped'; $reason = "stale alert ({$ageMin} min old when it arrived)"; }

            // by the symbol in the column; else by position — but never a column that names a different stock
            $byPos = is_array($cols[$i] ?? null) ? $cols[$i] : [];
            if (isset($byPos['symbol']) && is_string($byPos['symbol']) && strtoupper(trim($byPos['symbol'])) !== $symbol) $byPos = [];
            $col = $bySymbol[$symbol] ?? $byPos;
            $price = isset($prices[$i]) && is_numeric($prices[$i]) && (float) $prices[$i] > 0 ? round((float) $prices[$i], 2) : null;
            $n = DB::table('live_signals')->insertOrIgnore([
                'raw_id' => $rawId, 'alert_name' => mb_substr($alert, 0, 100), 'exchange' => 'NSE', 'symbol' => $symbol, 'trigger_price' => $price,
                'scan_name' => $scan, 'sector' => $text($col['sector'] ?? null, 80), 'market_cap' => $text($col['marketcapname'] ?? null, 30),
                'alert_rsi' => $num($col['rsi'] ?? null), 'alert_close' => $num($col['close'] ?? null),
                'triggered_at' => $at->toDateTimeString(), 'received_at' => $now->toDateTimeString(),
                'status' => $status, 'reason' => $reason, 'processed_at' => $status === 'skipped' ? $now->toDateTimeString() : null, 'created_at' => $now->toDateTimeString(),
            ]);
            if ($n) $out['new']++; else $out['duplicates']++;
        }

        return $out;
    }
}
