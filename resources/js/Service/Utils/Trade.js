// Display logic for the Trade panel — kept out of the component so it can be tested on its own.

import { decorate } from '@/Service/Utils/Watchlist.js';
import { fmtNum, fmtPrice, fmtDate } from '@/Service/Utils/Format.js';

const ACTION_CHIPS = { buy: 'chip-green', sell: 'chip-red', hold: 'chip-amber', avoid: 'chip-neutral' };
export const actionChip = (action) => ACTION_CHIPS[action] || 'chip-neutral';

const PAPER_CHIPS = { pending: 'chip-amber', open: 'chip-green', stopped: 'chip-red', target_hit: 'chip-green', expired: 'chip-neutral' };
export const paperChip = (status) => PAPER_CHIPS[status] || 'chip-neutral';

const PAPER_LABELS = { pending: 'Waiting for entry', open: 'Open', stopped: 'Stopped out', target_hit: 'Target hit', expired: 'Expired — never filled' };
export const paperLabel = (status) => PAPER_LABELS[status] || status;

const rupees = (v) => (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);

/** One plain sentence about where a paper trade stands. */
export function paperText(p) {
    if (!p) return '';
    const kind = p.entry_type === 'stop' ? 'breakout (buy-stop)' : 'pullback (buy-limit)';

    switch (p.status) {
        case 'pending':
            return `Waiting for the ${kind} entry at ${fmtPrice(p.entry_price)}. It lapses if not reached within ${p.valid_days} sessions after ${fmtDate(p.signal_date)}.`;
        case 'open':
            return `Filled on ${fmtDate(p.filled_on)} at ${fmtPrice(p.fill_price)}. Last close ${fmtPrice(p.mark_price)} → ${rupees(p.pnl)} unrealised.`;
        case 'stopped':
        case 'target_hit':
            return `${p.status === 'target_hit' ? 'Target' : 'Stop loss'} reached on ${fmtDate(p.closed_on)}: bought ${fmtPrice(p.fill_price)}, sold ${fmtPrice(p.exit_price)} → ${rupees(p.pnl)} (${p.r_multiple > 0 ? '+' : ''}${p.r_multiple}R).`;
        case 'expired':
            return `The ${kind} entry at ${fmtPrice(p.entry_price)} was not reached within ${p.valid_days} sessions — no trade, no win or loss.`;
        default:
            return '';
    }
}

/** Position size for a signal's levels, from the max-loss setting (same maths as the watchlist). Null if it has no levels. */
export function signalSizing(signal, ltp, maxLoss) {
    if (!signal || signal.entry_price === null || signal.stop_loss === null) return null;
    return decorate({ ltp: ltp ?? -1, buy_price: signal.entry_price, stop_loss: signal.stop_loss }, maxLoss);
}

/** How the user's existing holding is doing: % gain/loss vs average cost, or null. */
export function holdingPnlPct(holding, ltp) {
    if (!holding || !(ltp > 0) || !(holding.avg_price > 0)) return null;
    return Math.round((ltp / holding.avg_price - 1) * 10000) / 100;
}

/** The one-line scoreboard: is following Claude's BUY calls making money? */
export function statsLine(stats) {
    if (!stats || (!stats.buys && !stats.signals)) return 'No analyses yet.';
    if (!stats.closed) {
        const live = [];
        if (stats.open) live.push(`${stats.open} open`);
        if (stats.pending) live.push(`${stats.pending} waiting for entry`);
        if (stats.expired) live.push(`${stats.expired} never filled`);
        return `${stats.buys} paper trade${stats.buys === 1 ? '' : 's'}, none closed yet` + (live.length ? ` (${live.join(', ')})` : '') + '.';
    }
    const parts = [
        `${stats.closed} closed`,
        `${stats.win_rate}% won`,
        `avg ${stats.avg_r > 0 ? '+' : ''}${stats.avg_r}R`,
        `realised ${rupees(stats.realised)}`,
    ];
    if (stats.open) parts.push(`${stats.open} open (${rupees(stats.unrealised)})`);
    if (stats.pending) parts.push(`${stats.pending} waiting`);
    if (stats.expired) parts.push(`${stats.expired} never filled`);
    return parts.join(' · ');
}

// ─── The Trade Signals page ─────────────────────────────────────────────────

/** Where the entry sits on the stop → target bar, 0–100 (the bar's left end is the stop, its right end the target). */
export function entryPct(r) {
    const ref = r.fill_price ?? r.entry_price;
    if (!(r.target_price > r.stop_loss)) return null;
    return Math.round(Math.max(0, Math.min(100, (ref - r.stop_loss) / (r.target_price - r.stop_loss) * 100)) * 10) / 10;
}

/** The warning for a live price that is already through the stop / target before the candles confirm it. */
export function flagText(r) {
    if (r.live_flag === 'below_stop')   return `Price ${fmtPrice(r.ltp)} is at or below the stop ${fmtPrice(r.stop_loss)} — it will show as stopped once the day's candle is in`;
    if (r.live_flag === 'above_target') return `Price ${fmtPrice(r.ltp)} is at or above the target ${fmtPrice(r.target_price)} — it will show as target hit once the day's candle is in`;
    return '';
}

/** A pending trade: how far the entry is from the price, in words. */
export function entryNote(r) {
    if (r.to_entry_pct === null || r.to_entry_pct === undefined) return 'no price yet';
    const d = Math.abs(r.to_entry_pct);
    if (d < 0.05) return 'at the price now';
    return r.to_entry_pct < 0 ? `${fmtNum(d, 2)}% below the price — waits for a pullback` : `${fmtNum(d, 2)}% above the price — waits for a breakout`;
}

/** Sessions left before an unfilled entry lapses is unknown without a trading calendar, so show the plain rule instead. */
export const validText = (r) => `valid ${r.valid_days} sessions from ${fmtDate(r.signal_date)}`;
