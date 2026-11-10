<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use App\Models\User;
use App\Models\PaperTrade;
use App\Models\TradeSignal;
use App\Models\PortfolioHolding;
use App\Models\WatchlistItem;
use App\Services\KiteService;
use App\Services\Trading\PaperTradeService;

/**
 * The Trade Signals page: every stock that is currently in a paper trade made from an AI signal, with where it stands against its stop and target.
 * "In a trade" = a pending entry order waiting to fill, or a filled, still-open position. Closed ones are on a second tab.
 */
class TradeSignalsController extends Controller
{
    const HISTORY_LIMIT = 100;

    public function index()
    {
        if (!config('services.trade_signals.enabled')) return redirect('/portfolio');

        return Inertia::render('TradeSignals/Index')->withViewData(['sidebar' => 'trade-signals']);
    }

    public function list(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        if (!config('services.trade_signals.enabled')) return Response::json(['success' => false, 'message' => 'Trade signals are switched off (TRADE_SIGNALS_ENABLED=false in .env)'], 200);

        // bring every live trade up to date with the candles stored since it was opened
        PaperTradeService::settle();

        $active = PaperTrade::whereIn('status', ['pending', 'open'])->orderBy('symbol')->get();
        $closed = PaperTrade::whereIn('status', ['stopped', 'target_hit', 'expired'])
            ->orderByRaw('COALESCE(closed_on, signal_date) DESC')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();

        $kite = KiteService::getLtps($active->map(fn ($t) => $t->exchange . ':' . $t->symbol)->unique()->values()->all(), $request->boolean('refresh'));

        $signals = TradeSignal::whereIn('id', $active->pluck('signal_id')->merge($closed->pluck('signal_id'))->all())->get()->keyBy('id');

        // starred symbols, as everywhere else
        $followed = [];
        foreach (PortfolioHolding::where('follow', 1)->get() as $h) $followed[$h->exchange . ':' . $h->zerodha_symbol] = true;
        foreach (WatchlistItem::where('follow', 1)->get() as $i)    $followed[$i->exchange . ':' . $i->symbol] = true;

        $rows = $active->map(fn ($t) => self::row($t, $signals[$t->signal_id] ?? null, $kite['ltps'][$t->exchange . ':' . $t->symbol] ?? KiteService::NO_LTP, isset($followed[$t->exchange . ':' . $t->symbol])))->values();

        $open = $rows->where('status', 'open');
        $summary = [
            'open'       => $open->count(),
            'pending'    => $rows->where('status', 'pending')->count(),
            'invested'   => round($open->sum(fn ($r) => $r['fill_price'] * $r['qty']), 2),
            'at_risk'    => round($open->sum(fn ($r) => max(0, $r['fill_price'] - $r['stop_loss']) * $r['qty']), 2),
            'unrealised' => round($open->sum(fn ($r) => $r['pnl'] ?? 0), 2),
            'alerts'     => $rows->whereNotNull('live_flag')->count(),
        ];

        return Response::json([
            'success'   => true,
            'active'    => $rows,
            'history'   => $closed->map(fn ($t) => self::row($t, $signals[$t->signal_id] ?? null, KiteService::NO_LTP, isset($followed[$t->exchange . ':' . $t->symbol])))->values(),
            'summary'   => $summary,
            'stats'     => PaperTradeService::stats(),
            'ltp_error' => $kite['error'] ?? null,
        ], 200);
    }

    /** One trade as the page shows it. Pure arithmetic on the trade + the live price, so it is testable on its own. */
    public static function row(PaperTrade $t, ?TradeSignal $signal, float $ltp, bool $followed): array
    {
        $entry = (float) $t->entry_price;
        $stop  = (float) $t->stop_loss;
        $tgt   = (float) $t->target_price;
        $fill  = $t->fill_price !== null ? (float) $t->fill_price : null;
        $live  = in_array($t->status, ['pending', 'open'], true);

        // the freshest price we have: the live quote, else the last stored close
        $price = $live ? ($ltp > 0 ? $ltp : ($t->mark_price !== null ? (float) $t->mark_price : null)) : null;

        $pnl = $pnl_pct = $r_now = $progress = $to_stop = $to_target = $to_entry = $flag = null;

        if ($t->status === 'open' && $price !== null && $fill) {
            $pnl     = round(($price - $fill) * $t->qty, 2);
            $pnl_pct = round(($price / $fill - 1) * 100, 2);
            $risk    = $fill - $stop;
            $r_now   = $risk > 0 ? round(($price - $fill) / $risk, 2) : null;
        } elseif (!$live) {
            $pnl = $t->pnl !== null ? (float) $t->pnl : null;
        }

        if ($live && $price !== null) {
            $to_stop   = round(($price - $stop) / $price * 100, 2);     // % the price can still fall before the stop
            $to_target = round(($tgt - $price) / $price * 100, 2);      // % it still has to rise to the target
            $to_entry  = round(($entry - $price) / $price * 100, 2);    // pending: % to the entry level (negative = entry is below the price)
            $progress  = $tgt > $stop ? round(max(0, min(100, ($price - $stop) / ($tgt - $stop) * 100)), 1) : null;

            // The stored candles only update after the close; a live price can already be through a level.
            if ($t->status === 'open' && $ltp > 0) {
                if ($ltp <= $stop)     $flag = 'below_stop';
                elseif ($ltp >= $tgt)  $flag = 'above_target';
            }
        }

        return [
            'id' => $t->id, 'exchange' => $t->exchange, 'symbol' => $t->symbol, 'followed' => $followed,
            'status' => $t->status, 'entry_type' => $t->entry_type, 'qty' => (int) $t->qty,
            'entry_price' => $entry, 'stop_loss' => $stop, 'target_price' => $tgt, 'fill_price' => $fill,
            'valid_days' => (int) $t->valid_days,
            'signal_date' => (string) $t->signal_date, 'filled_on' => $t->filled_on ? (string) $t->filled_on : null, 'closed_on' => $t->closed_on ? (string) $t->closed_on : null,
            'exit_price' => $t->exit_price !== null ? (float) $t->exit_price : null, 'r_multiple' => $t->r_multiple !== null ? (float) $t->r_multiple : null,
            'ltp' => $ltp, 'price' => $price,
            'pnl' => $pnl, 'pnl_pct' => $pnl_pct, 'r_now' => $r_now, 'progress' => $progress,
            'to_stop_pct' => $to_stop, 'to_target_pct' => $to_target, 'to_entry_pct' => $to_entry, 'live_flag' => $flag,
            'pattern' => $signal->pattern ?? null, 'confidence' => $signal->confidence ?? null,
        ];
    }
}
