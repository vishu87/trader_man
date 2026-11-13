<?php

namespace App\Services\Live;

use Illuminate\Support\Facades\DB;

/**
 * Cleans live_signals of repeat signals, from the very first row: ONE signal per stock per day stays — the one triggered first — and every later
 * one for that stock that day moves to live_signal_duplicates (or is deleted outright). The same rule the webhook applies to new signals
 * (ChartinkWebhook::firstSignal):
 *   - signals of ENABLED alerts are compared across all enabled alerts (alert B's later signal is a repeat of alert A's first);
 *   - signals of alerts that are not enabled (record-only) are compared within their own alert only.
 * It never touches a signal that is still live (new / working / watching / enter) or one that was bought (status done, or a position / order points at
 * it) — those are reported, not moved. A moved signal's check history goes with it.
 * Safe to run again: a second run finds nothing.
 */
class SignalDeduper
{
    /**
     * @param bool $apply  false = only count what would be moved (dry run)
     * @param bool $delete true = delete the repeats instead of moving them to the duplicates table
     * @return array{groups: int, repeats: int, moved: int, kept_active: int, kept_bought: int, by_alert: array, by_day: array, sample: array, applied: bool, deleted: bool, enabled_alerts: array}
     */
    public static function run(bool $apply = true, bool $delete = false): array
    {
        $enabled = LiveConfig::get()['alert_names'];
        $out = ['groups' => 0, 'repeats' => 0, 'moved' => 0, 'kept_active' => 0, 'kept_bought' => 0, 'by_alert' => [], 'by_day' => [], 'sample' => [], 'applied' => $apply, 'deleted' => $apply && $delete, 'enabled_alerts' => $enabled];

        $bought = array_flip(array_merge(
            DB::table('live_positions')->whereNotNull('signal_id')->pluck('signal_id')->all(),
            DB::table('live_orders')->whereNotNull('signal_id')->pluck('signal_id')->all()
        ));

        // every stock-and-day that has more than one signal
        $groups = DB::select('SELECT symbol, DATE(triggered_at) AS d FROM live_signals GROUP BY symbol, DATE(triggered_at) HAVING COUNT(*) > 1');
        foreach ($groups as $g) {
            $rows = DB::table('live_signals')->where('symbol', $g->symbol)->where('triggered_at', '>=', $g->d . ' 00:00:00')->where('triggered_at', '<=', $g->d . ' 23:59:59')
                ->orderBy('triggered_at')->orderBy('id')->get();

            // the enabled alerts compete with each other; every other alert only with itself
            $sets = [];
            foreach ($rows as $r) $sets[in_array($r->alert_name, $enabled, true) ? '*enabled*' : $r->alert_name][] = $r;

            foreach ($sets as $set) {
                if (count($set) < 2) continue;
                $out['groups']++;
                $first = $set[0];
                foreach (array_slice($set, 1) as $r) {
                    $out['repeats']++;
                    if (in_array($r->status, ['new', 'working', 'watching', 'enter'], true)) { $out['kept_active']++; continue; }
                    if ($r->status === 'done' || isset($bought[$r->id])) { $out['kept_bought']++; continue; }

                    $out['by_alert'][$r->alert_name] = ($out['by_alert'][$r->alert_name] ?? 0) + 1;
                    $out['by_day'][$g->d] = ($out['by_day'][$g->d] ?? 0) + 1;
                    if (count($out['sample']) < 12) $out['sample'][] = ['symbol' => $r->symbol, 'day' => $g->d, 'alert' => $r->alert_name, 'at' => substr($r->triggered_at, 11, 5), 'first_alert' => $first->alert_name, 'first_at' => substr($first->triggered_at, 11, 5)];
                    if (!$apply) { $out['moved']++; continue; }

                    DB::transaction(function () use ($r, $first, $delete) {
                        if (!$delete) {
                            DB::table('live_signal_duplicates')->insertOrIgnore([
                                'raw_id' => $r->raw_id, 'alert_name' => $r->alert_name, 'scan_name' => $r->scan_name, 'exchange' => $r->exchange, 'symbol' => $r->symbol,
                                'trigger_price' => $r->trigger_price, 'sector' => $r->sector, 'market_cap' => $r->market_cap, 'alert_rsi' => $r->alert_rsi, 'alert_close' => $r->alert_close,
                                'triggered_at' => $r->triggered_at, 'received_at' => $r->received_at, 'repeat_of' => $first->id, 'first_alert' => mb_substr($first->alert_name, 0, 100),
                                'reason' => mb_substr('repeat of the ' . substr($first->triggered_at, 11, 5) . ' signal (#' . $first->id . ', ' . $first->alert_name . ', ' . ChartinkWebhook::stateOf($first) . ')', 0, 190),
                                'created_at' => $r->created_at,
                            ]);
                        }
                        DB::table('live_signal_checks')->where('signal_id', $r->id)->delete();
                        DB::table('live_signals')->where('id', $r->id)->delete();
                    });
                    $out['moved']++;
                }
            }
        }
        ksort($out['by_day']);
        arsort($out['by_alert']);
        return $out;
    }
}
