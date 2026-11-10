<script setup>
import { ref, computed } from 'vue';
import { fmtNum, fmtPrice, fmtDate, pnlClass } from '@/Service/Utils/Format.js';

// One row per SIGNAL that became a trade, with its own profit or loss (a trade is entered on a signal and may run for days).
// Click a heading to sort. The chart icon opens that stock's day with the trade drawn on it.
const props = defineProps({
    trades: { type: Array, required: true },
});
const emit = defineEmits(['chart']);

const money = (v) => v === null || v === undefined ? '—' : (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);
const hm = (s) => s ? s.slice(11, 16) : '';
const waited = (t) => { const m = Math.round((new Date(t.signal_at.replace(' ', 'T')) - new Date(t.orig_signal_at.replace(' ', 'T'))) / 60000); return m >= 60 ? Math.floor(m / 60) + 'h ' + (m % 60) + 'm' : m + ' min'; };
const REASON = { close: 'chip-neutral', 'day end': 'chip-green', 'next open': 'chip-neutral', target: 'chip-green', trail: 'chip-green', stop: 'chip-red', time: 'chip-amber', open: 'chip-amber' };

const sortKey = ref('signal_at');
const sortDir = ref(1);
const filter  = ref('all');   // all | won | lost | open

function sortBy(k) {
    if (sortKey.value === k) sortDir.value = -sortDir.value; else { sortKey.value = k; sortDir.value = k === 'signal_at' || k === 'symbol' ? 1 : -1; }
}
const arrow = (k) => sortKey.value === k ? (sortDir.value > 0 ? ' ▲' : ' ▼') : '';

const rows = computed(() => {
    let r = props.trades.filter(t => filter.value === 'all' || (filter.value === 'won' && t.net > 0) || (filter.value === 'lost' && t.net <= 0 && !t.open) || (filter.value === 'open' && t.open));
    const k = sortKey.value, d = sortDir.value;
    return r.slice().sort((a, b) => (a[k] < b[k] ? -1 : a[k] > b[k] ? 1 : 0) * d || a.signal_at.localeCompare(b.signal_at));
});
const total = computed(() => rows.value.reduce((s, t) => s + t.net, 0));
</script>

<template>
    <div>
        <div class="d-flex align-items-center gap-2 px-3 py-2 flex-wrap">
            <span class="cell-secondary" style="margin:0">Show</span>
            <button v-for="f in [['all', 'All'], ['won', 'Winners'], ['lost', 'Losers'], ['open', 'Still open']]" :key="f[0]" class="pf-tab" :class="{ active: filter === f[0] }" style="padding:4px 10px" @click="filter = f[0]">{{ f[1] }}</button>
            <span class="ms-auto text-muted" style="font-size:13px">{{ rows.length }} trade{{ rows.length === 1 ? '' : 's' }} · <span :class="pnlClass(total)" class="fw-semibold">{{ money(total) }}</span></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th role="button" @click="sortBy('signal_at')">Signal{{ arrow('signal_at') }}</th>
                        <th role="button" @click="sortBy('symbol')">Stock{{ arrow('symbol') }}</th>
                        <th>Bought</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Avg</th>
                        <th role="button" @click="sortBy('exit_at')">Sold{{ arrow('exit_at') }}</th>
                        <th class="text-end">Price</th>
                        <th>How</th>
                        <th class="text-end" role="button" @click="sortBy('held_days')">Days{{ arrow('held_days') }}</th>
                        <th class="text-end" role="button" @click="sortBy('net')">Net ₹{{ arrow('net') }}</th>
                        <th class="text-end" role="button" @click="sortBy('net_pct')">Net %{{ arrow('net_pct') }}</th>
                        <th class="text-end">High / Low</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="12" class="text-center py-5 text-muted">No trades with these rules.</td></tr>
                    <tr v-for="t in rows" :key="t.symbol + t.entry_at">
                        <td class="text-nowrap">{{ fmtDate(t.orig_signal_at || t.signal_at) }}<div class="cell-secondary mono" style="margin:0">{{ hm(t.orig_signal_at || t.signal_at) }}<template v-if="t.signals > 1 && !t.orig_signal_at"> · #{{ t.signals }}</template></div>
                            <div v-if="t.orig_signal_at" class="cell-secondary mono" style="margin:0" title="Waited for a good entry">→ {{ hm(t.signal_at) }} · waited {{ waited(t) }}</div></td>
                        <td><span class="cell-primary mono">{{ t.symbol }}</span><button class="ls-chart-btn" title="View the chart of the day with this trade on it" @click="emit('chart', t)"><i class="bi bi-graph-up"></i></button><div class="cell-secondary">{{ t.cap }}</div></td>
                        <td class="text-nowrap">
                            <div v-for="(tr, i) in t.tranches" :key="i" class="mono" style="font-size:12px">{{ fmtDate(tr.at).slice(0, 6) }} {{ hm(tr.at) }} · {{ fmtNum(tr.qty) }} @ {{ fmtPrice(tr.price) }}</div>
                        </td>
                        <td class="text-num">{{ fmtNum(t.qty) }}</td>
                        <td class="text-num">{{ fmtPrice(t.avg_price) }}</td>
                        <td class="text-nowrap">{{ t.exit_reason === 'open' ? 'still open' : fmtDate(t.exit_at).slice(0, 6) + ' ' + hm(t.exit_at) }}</td>
                        <td class="text-num">{{ fmtPrice(t.exit_price) }}</td>
                        <td><span class="status-chip" :class="REASON[t.exit_reason] || 'chip-neutral'">{{ t.exit_reason }}</span></td>
                        <td class="text-num">{{ t.held_days }}</td>
                        <td class="text-num fw-semibold" :class="pnlClass(t.net)">{{ money(t.net) }}</td>
                        <td class="text-num" :class="pnlClass(t.net_pct)">{{ t.net_pct > 0 ? '+' : '' }}{{ fmtNum(t.net_pct, 2) }}%</td>
                        <td class="text-num text-muted"><span class="pnl-pos">+{{ fmtNum(t.mfe_pct, 1) }}%</span> / <span class="pnl-neg">{{ fmtNum(t.mae_pct, 1) }}%</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
