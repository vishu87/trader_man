// Everything a watchlist row shows that is DERIVED from its raw values (LTP, buy price, stop loss) and the
// max-loss setting. One place, so it updates instantly when a price is edited inline or the setting changes.

// half away from zero, so -3.125 and 3.125 round alike (Math.round alone sends -3.125 to -3.12 but 3.125 to 3.13)
const round2 = (v) => Math.sign(v) * Math.round(Math.abs(v) * 100) / 100;

/**
 * @param item    raw row from /api/watchlist/data  ({ ltp, buy_price, stop_loss, ... }; ltp -1 = no price)
 * @param maxLoss the most you accept losing on one trade (₹), from the settings
 */
export function decorate(item, maxLoss) {
    const ltp = item.ltp;
    const buy = item.buy_price;
    const sl  = item.stop_loss;
    const hasLtp = ltp > 0;
    const hasBuy = buy !== null && buy !== undefined && buy > 0;
    const hasSl  = sl !== null && sl !== undefined;

    let status = null;
    if (hasLtp && hasSl && ltp <= sl)        status = 'sl_hit';
    else if (hasLtp && hasBuy && ltp <= buy) status = 'buy_zone';

    // Position sizing — needs both prices, and a stop loss below the buy price:
    //   risk per share = buy - stop loss;   quantity = floor(max loss / risk per share)
    const riskPerShare = hasBuy && hasSl && buy > sl ? round2(buy - sl) : null;
    // 1e-9 guards against 4.999999999 when the division is exact on paper
    const qty = riskPerShare && maxLoss > 0 ? Math.floor(maxLoss / riskPerShare + 1e-9) : null;

    return {
        ...item,
        vs_buy_pct:     hasLtp && hasBuy ? round2((ltp - buy) / buy * 100) : null,   // negative = cheaper than your buy price
        sl_dist_pct:    hasLtp && hasSl ? round2((ltp - sl) / ltp * 100) : null,     // <= 0 means the stop loss is hit
        status,
        risk_per_share: riskPerShare,
        risk_pct:       riskPerShare ? round2(riskPerShare / buy * 100) : null,     // stop loss distance, % of the buy price
        qty,
        capital:        qty !== null ? round2(qty * buy) : null,                    // money needed to buy that quantity
        loss_at_sl:     qty !== null ? round2(qty * riskPerShare) : null,           // what you actually lose if the stop loss hits
    };
}
