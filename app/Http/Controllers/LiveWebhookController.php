<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Live\ChartinkWebhook;
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
            if ($secret === '') return response('The webhook secret is not set on the server', 503);
            if (!hash_equals($secret, (string) $given)) return response('Not found', 404);   // a wrong secret learns nothing
        } elseif ($request->route('token') !== null || $request->path() !== 'api/chart-ink-alerts') {
            return response('Not found', 404);
        } elseif (LiveConfig::get()['mode'] === 'live') {
            return response('In live mode the webhook URL needs ?key=<secret>', 403);
        }

        $r = ChartinkWebhook::receive($request->all(), $request->ip());
        if ($r['new'] > 0) Runner::processInBackground();

        return response('OK', 200);
    }
}
