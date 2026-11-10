// Number formatting helpers (Indian digit grouping)

export function fmtNum(v, decimals = 0) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-IN', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export const fmtPrice = (v) => fmtNum(v, 2);

export function fmtPct(v) {
    if (v === null || v === undefined) return '—';
    return (v > 0 ? '+' : '') + fmtNum(v, 2) + '%';
}

export function pnlClass(v) {
    if (v === null || v === undefined || v === 0) return '';
    return v > 0 ? 'pnl-pos' : 'pnl-neg';
}

// 'YYYY-MM-DD' -> '12 Nov 2026' (parsed by hand: new Date('2026-11-12') is UTC and can land on the wrong day)
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function fmtDate(v) {
    if (!v) return '—';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return `${d} ${MONTHS[Number(m) - 1]} ${y}`;
}

// today as 'YYYY-MM-DD' in the browser's own timezone, comparable with the dates above
export function todayString() {
    const t = new Date();
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
}
