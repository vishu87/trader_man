<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use App\Models\AppSetting;
use App\Models\LiveOrder;
use App\Models\LivePosition;
use App\Models\LiveSignal;
use App\Models\LiveSignalCheck;
use App\Models\User;
use App\Services\Live\BrokerFactory;
use App\Services\DayCandles;
use App\Services\Live\Clock;
use App\Services\Live\LiveConfig;
use App\Services\Live\LiveTick;

/**
 * Live trading screens: the Live Signals page (what arrived, what we did, what we hold) and the Live Config page.
 * The trading itself is App\Services\Live — this only shows it and saves its settings.
 */
class LiveController extends Controller
{
    public function signalsPage()
    {
        return Inertia::render('LiveSignals/Index')->withViewData(['sidebar' => 'live-signals']);
    }

    public function configPage()
    {
        return Inertia::render('LiveConfig/Index')->withViewData(['sidebar' => 'live-config']);
    }

    // ─── the numbers at the top of the page ──────────────────────────────────

    public function overview(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $cfg = LiveConfig::get();
        $now = Clock::now();
        $today = $now->toDateString();

        $open = LivePosition::where('status', 'open')->get();
        $unrealised = 0.0;
        foreach ($open as $p) if ($p->last_price) $unrealised += ((float) $p->last_price - (float) $p->avg_price) * $p->qty;

        $lastTick = AppSetting::value('live_last_tick');
        $attention = [];
        foreach (LiveOrder::where('status', 'unknown')->get() as $o) $attention[] = "{$o->symbol}: an order was being sent when the process stopped — check Zerodha";
        $n = LiveSignal::where('status', 'failed')->where('received_at', '>=', $today . ' 00:00:00')->count();
        if ($n) $attention[] = "{$n} signal(s) failed today — see the reasons in the Signals tab";
        foreach (LivePosition::where('status', 'open')->get() as $p) {
            if (LiveOrder::where('position_id', $p->id)->where('side', 'sell')->where('status', 'rejected')->where('placed_at', '>=', $now->copy()->subMinutes(15)->toDateTimeString())->count() >= LiveTick::MAX_REJECTED_EXITS) {
                $attention[] = "{$p->symbol}: Zerodha keeps refusing the sell — sell it by hand";
            }
        }
        if ($cfg['enabled'] && Clock::marketOpen($now) && (!$lastTick || strtotime($lastTick) < $now->timestamp - 180)) {
            $attention[] = 'The once-a-minute job (live:tick) has not run for 3 minutes — is the scheduler cron installed?';
        }

        $secret = (string) config('services.chartink.webhook_secret');
        $open_slots = max(0, $cfg['max_open_positions'] - LivePosition::whereIn('status', ['pending', 'open'])->count());

        return Response::json([
            'success' => true, 'max_positions' => $cfg['max_open_positions'], 'open_slots' => $open_slots,
            'config'  => ['enabled' => $cfg['enabled'], 'mode' => $cfg['mode'], 'alert_names' => $cfg['alert_names']],
            'now' => $now->toDateTimeString(), 'market_open' => Clock::marketOpen($now), 'last_tick' => $lastTick,
            'open_positions' => $open->count(), 'pending_positions' => LivePosition::where('status', 'pending')->count(),
            'capital_held' => round((float) $open->sum('invested'), 2), 'unrealised' => round($unrealised, 2),
            'realised_today' => round((float) LivePosition::where('status', 'closed')->where('closed_at', '>=', $today . ' 00:00:00')->sum('pnl'), 2),
            'signals_today' => LiveSignal::where('received_at', '>=', $today . ' 00:00:00')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'attention' => $attention,
            'webhook_ready' => $cfg['mode'] !== 'live' || $secret !== '',   // dry run: the open URL works; live mode needs the secret
        ], 200);
    }

    // ─── the lists ───────────────────────────────────────────────────────────

    public function signals(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json(['success' => true, 'signals' => LiveSignal::orderByDesc('id')->limit(300)->get()->map(fn ($s) => [
            'id' => $s->id, 'alert_name' => $s->alert_name, 'symbol' => $s->symbol, 'exchange' => $s->exchange, 'trigger_price' => $s->trigger_price !== null ? (float) $s->trigger_price : null,
            'sector' => $s->sector, 'market_cap' => $s->market_cap, 'alert_rsi' => $s->alert_rsi !== null ? (float) $s->alert_rsi : null,
            'triggered_at' => $s->triggered_at, 'received_at' => $s->received_at, 'status' => $s->status, 'verdict' => $s->verdict, 'score' => $s->score,
            'action' => $s->action, 'reason' => $s->reason, 'position_id' => $s->position_id, 'checks' => (int) $s->checks, 'checked_at' => $s->checked_at,
        ])], 200);
    }

    /** One signal's status over time: every look the engine took at it. */
    public function signalChecks(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $id = (int) $request->input('id');
        $s = LiveSignal::find($id);
        if (!$s) return Response::json(['success' => false, 'message' => 'Unknown signal'], 200);

        $checks = LiveSignalCheck::where('signal_id', $id)->orderBy('id')->get()->map(function ($c) {
            $d = json_decode((string) $c->details, true) ?: [];
            $f = $d['f'] ?? [];
            return [
                'id' => $c->id, 'checked_at' => $c->checked_at, 'candle' => $c->candle_at ? substr($c->candle_at, 11, 5) : null, 'kind' => $c->kind, 'verdict' => $c->verdict, 'score' => $c->score,
                'price' => $c->price !== null ? (float) $c->price : null, 'ltp' => $c->ltp !== null ? (float) $c->ltp : null, 'fails' => $c->fails ? explode(',', $c->fails) : [], 'note' => $c->note,
                'rsi' => isset($f['rsi']) ? round($f['rsi'], 1) : null, 'vwap_dist' => $f['vwap_dist'] ?? null, 'day_chg' => $f['day_chg'] ?? null, 'from_low' => $f['from_low'] ?? null,
                'vol_ratio' => $f['vol_ratio'] ?? null, 'green' => $f['green'] ?? null,
            ];
        })->values();

        return Response::json(['success' => true, 'checks' => $checks, 'status' => $s->status, 'reason' => $s->reason], 200);
    }

    public function positions(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $cfg = LiveConfig::get();
        $row = function (LivePosition $p) use ($cfg) {
            $cost = (float) $p->avg_price * ($p->status === 'closed' ? $p->sold_qty : $p->qty);
            $un = $p->status === 'open' && $p->last_price ? ((float) $p->last_price - (float) $p->avg_price) * $p->qty : null;
            return [
                'id' => $p->id, 'symbol' => $p->symbol, 'exchange' => $p->exchange, 'mode' => $p->mode, 'status' => $p->status, 'qty' => $p->qty, 'avg_price' => (float) $p->avg_price, 'invested' => (float) $p->invested,
                'adds' => $p->adds, 'sold_qty' => $p->sold_qty, 'hold_until' => $p->hold_until ? substr((string) $p->hold_until, 0, 10) : null, 'last_price' => $p->last_price !== null ? (float) $p->last_price : null,
                'unrealised' => $un !== null ? round($un, 2) : null, 'unrealised_pct' => $un !== null && $p->avg_price > 0 ? round(((float) $p->last_price / (float) $p->avg_price - 1) * 100, 2) : null,
                'target_price' => $cfg['target_pct'] !== null && $p->avg_price > 0 ? round((float) $p->avg_price * (1 + $cfg['target_pct'] / 100), 2) : null,
                'stop_price' => $cfg['stop_pct'] !== null && $p->avg_price > 0 ? round((float) $p->avg_price * (1 - $cfg['stop_pct'] / 100), 2) : null,
                'exit_requested_at' => $p->exit_requested_at, 'signal_id' => $p->signal_id,
                'opened_at' => $p->opened_at, 'closed_at' => $p->closed_at, 'exit_price' => $p->exit_price !== null ? (float) $p->exit_price : null, 'exit_reason' => $p->exit_reason,
                'pnl' => $p->pnl !== null ? (float) $p->pnl : null, 'pnl_pct' => $p->pnl !== null && $cost > 0 ? round((float) $p->pnl / $cost * 100, 2) : null, 'created_at' => $p->created_at,
            ];
        };

        return Response::json([
            'success' => true,
            'open'   => LivePosition::whereIn('status', ['pending', 'open'])->orderBy('id')->get()->map($row)->values(),
            'closed' => LivePosition::whereIn('status', ['closed', 'cancelled', 'failed'])->orderByDesc('id')->limit(150)->get()->map($row)->values(),
        ], 200);
    }

    /** The Exit button on a position. */
    public function exitPosition(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        try {
            $r = (new LiveTick(BrokerFactory::make()))->manualExit((int) $request->input('id'));
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Could not exit: ' . $e->getMessage()], 200);
        }
        return Response::json(['success' => $r['ok'], 'message' => $r['message']], 200);
    }

    public function orders(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json(['success' => true, 'orders' => LiveOrder::orderByDesc('id')->limit(300)->get()->map(fn ($o) => [
            'id' => $o->id, 'position_id' => $o->position_id, 'symbol' => $o->symbol, 'side' => $o->side, 'kind' => $o->kind, 'qty' => $o->qty, 'order_type' => $o->order_type,
            'price' => $o->price !== null ? (float) $o->price : null, 'status' => $o->status, 'filled_qty' => $o->filled_qty, 'filled_price' => $o->filled_price !== null ? (float) $o->filled_price : null,
            'mode' => $o->mode, 'broker_order_id' => $o->broker_order_id, 'message' => $o->message, 'placed_at' => $o->placed_at,
        ])], 200);
    }

    // ─── one stock's day ─────────────────────────────────────────────────────

    /**
     * The 5-minute chart of one stock on one day, with what live trading did on it: the alerts, the buys and sells, and the
     * average / stop / target lines of the position. The "20 SMA" line is the level the 2nd buy uses (the average of the 20 closes BEFORE each candle).
     */
    public function chart(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $symbol = strtoupper(trim((string) $request->input('symbol')));
        if (!preg_match('/^[A-Z0-9&\-\._]{1,50}$/', $symbol)) return Response::json(['success' => false, 'message' => 'Unknown stock'], 200);
        $date = (string) $request->input('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = Clock::now()->toDateString();

        $c = DayCandles::forDay($symbol, $date, false);   // Zerodha first
        if (!$c['ok']) return Response::json(['success' => false, 'message' => $c['message']], 200);
        $candles = $c['candles']; $prevClose = $c['prev_close']; $source = $c['source'];

        $cfg = LiveConfig::get();
        $alerts = LiveSignal::where('symbol', $symbol)->whereDate('triggered_at', $date)->orderBy('triggered_at')->get()->map(fn ($s) => [
            't' => substr($s->triggered_at, 11, 5), 'price' => $s->trigger_price !== null ? (float) $s->trigger_price : null, 'status' => $s->status, 'reason' => $s->reason, 'alert' => $s->alert_name,
        ])->values();
        $fills = LiveOrder::where('symbol', $symbol)->where('filled_qty', '>', 0)->whereDate('placed_at', $date)->orderBy('placed_at')->get()->map(fn ($o) => [
            't' => substr($o->placed_at, 11, 5), 'price' => (float) $o->filled_price, 'qty' => (int) $o->filled_qty, 'side' => $o->side, 'kind' => $o->kind, 'mode' => $o->mode,
        ])->values();

        // the position(s) held on this day: average, stop and target
        $lines = [];
        foreach (LivePosition::where('symbol', $symbol)->whereNotNull('opened_at')->whereDate('opened_at', '<=', $date)->orderBy('id')->get() as $p) {
            if ($p->closed_at && substr($p->closed_at, 0, 10) < $date) continue;
            $avg = (float) $p->avg_price;
            if ($avg <= 0) continue;
            $lines[] = ['label' => 'average', 'price' => round($avg, 2), 'color' => '#2E6BFF'];
            if ($cfg['stop_pct'] !== null)   $lines[] = ['label' => 'stop ' . $cfg['stop_pct'] . '%',     'price' => round($avg * (1 - $cfg['stop_pct'] / 100), 2),   'color' => '#E03131'];
            if ($cfg['target_pct'] !== null) $lines[] = ['label' => 'target ' . $cfg['target_pct'] . '%', 'price' => round($avg * (1 + $cfg['target_pct'] / 100), 2), 'color' => '#0E9F5B'];
        }

        return Response::json(['success' => true, 'symbol' => $symbol, 'date' => $date, 'source' => $source, 'prev_close' => $prevClose, 'candles' => $candles, 'alerts' => $alerts, 'fills' => $fills, 'lines' => $lines], 200);
    }

    // ─── run the once-a-minute job now ───────────────────────────────────────

    public function tick(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        try {
            $r = (new LiveTick(BrokerFactory::make()))->run();
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'The check failed: ' . $e->getMessage()], 200);
        }

        return Response::json(['success' => true, 'message' => $r['market_open'] ? 'Checked: ' . $r['signals'] . ' signal(s), ' . count($r['exits']) . ' exit(s) placed' : 'The market is closed — nothing to do', 'result' => $r], 200);
    }

    // ─── the config ──────────────────────────────────────────────────────────

    public function config(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $cfg = LiveConfig::get();
        $secret = (string) config('services.chartink.webhook_secret');

        return Response::json([
            'success' => true, 'config' => $cfg, 'defaults' => LiveConfig::defaults(), 'max_per_stock' => LiveConfig::maxPerStock($cfg),
            'webhook_url' => url('/api/chart-ink-alerts'), 'webhook_url_key' => $secret !== '' ? url('/api/chart-ink-alerts') . '?key=' . $secret : null,
            'broker_ready' => $this->brokerReady(),
        ], 200);
    }

    public function saveConfig(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $r = LiveConfig::save((array) $request->input('config', []), $request->boolean('confirm_live'));
        if (!$r['ok']) return Response::json(['success' => false, 'message' => reset($r['errors']) ?: 'Check the highlighted fields', 'errors' => $r['errors']], 200);

        return Response::json(['success' => true, 'message' => 'Saved', 'config' => LiveConfig::get(), 'max_per_stock' => LiveConfig::maxPerStock($r['config'])], 200);
    }

    /** Is there a Kite token for today (without calling Kite)? */
    private function brokerReady(): bool
    {
        return \App\Services\KiteService::hasToken();
    }
}
