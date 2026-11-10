<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\Services\Live\ChartinkWebhook;
use App\Services\Live\Clock;
use App\Services\Live\LiveConfig;
use App\Services\Live\Runner;

/**
 * The ChartInk webhook, the only public endpoint of the live trading module. It stores the alert and starts processing in the
 * background; it never calls the broker itself.
 *
 *   POST /api/chart-ink-alerts            the URL ChartInk is given. Open in dry-run; in LIVE mode it needs ?key=<secret> (real orders are never
 *                                         placed because of a call anyone could make).
 *   POST /api/chart-ink-alerts?key=…      always accepted with the right secret
 *   POST /api/chartink/<secret>           the older form, the secret in the path
 */
class LiveWebhookController extends Controller
{
    public function chartink(Request $request, ?string $token = null)
    {
        $secret = (string) config('services.chartink.webhook_secret');
        $given = $token ?? $request->query('key');   // the path form / ?key=

        if ($given !== null) {
            if ($secret === '') return $this->refuse('The webhook secret is not set on the server', 503);
            if (!hash_equals($secret, (string) $given)) return $this->refuse('Not found', 404);   // a wrong secret learns nothing
        } elseif ($request->route('token') !== null || $request->path() !== 'api/chart-ink-alerts') {
            return $this->refuse('Not found', 404);
        } elseif (LiveConfig::get()['mode'] === 'live') {
            return $this->refuse('In live mode the webhook URL needs ?key=<secret>', 403);
        }

        $r = ChartinkWebhook::receive($request->all(), $request->ip());
        if ($r['new'] > 0) Runner::processInBackground();

        $entries = $r['entries'];
        $by = fn (string $st) => count(array_filter($entries, fn ($e) => $e['status'] === $st));
        $message = $r['signals'] === 0
            ? 'Received, but the call has no stocks'
            : $r['signals'] . ' stock(s) received: ' . $by('new') . ' waiting for their candle, ' . $by('skipped') . ' skipped, ' . $r['duplicates'] . ' already stored from an earlier identical call';

        // everything stored, so a call can be checked from ChartInk's test button / curl without opening the database
        return Response::json([
            'success' => true, 'message' => $message,
            'received_at' => Clock::now()->toDateTimeString(), 'raw_id' => $r['raw_id'],
            'alert_name' => trim((string) $request->input('alert_name', '')) ?: null, 'scan_name' => $request->input('scan_name'), 'triggered_at_sent' => $request->input('triggered_at'),
            'counts' => ['stocks' => $r['signals'], 'new' => $r['new'], 'skipped' => $by('skipped'), 'duplicates' => $r['duplicates']],
            'entries' => $entries,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function refuse(string $message, int $status)
    {
        return Response::json(['success' => false, 'message' => $message], $status);
    }
}
