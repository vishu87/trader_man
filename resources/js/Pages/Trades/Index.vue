<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtPct, fmtDate, pnlClass } from '@/Service/Utils/Format.js';
import TradeForm from '@/Includes/Trades/TradeForm.vue';
import ActionForm from '@/Includes/Trades/ActionForm.vue';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';
import { useCandleSync } from '@/Service/Utils/CandleSync.js';

// Real trades: a stock, and every buy and sell you made in it. What is still held is valued at the live price (unrealised);
// what was sold is realised. Active trades are the ones you are in; archived ones are done with.
const loading  = ref(false);
const loaded   = ref(false);
const trades   = ref([]);
const summary  = ref({});
const archivedSummary = ref({});
const ltpError = ref(null);

const tab    = ref('active');       // 'active' | 'archived'
const search = ref('');
const open   = ref({});             // trade id → its buys and sells are expanded

const sortBy  = ref('symbol');
const sortDir = ref('asc');

function fetchData(refresh = false) {
    loading.value = true;
    return DBService.postData('/api/trades/list', { refresh: refresh ? 1 : 0 }).then((data) => {
        loading.value = false;
        loaded.value = true;
        if (!data.success) { alert(data.message); return; }
        trades.value  = data.trades;
        summary.value = data.summary;
        archivedSummary.value = data.archived_summary;
        ltpError.value = data.ltp_error;
    });
}

onMounted(() => { fetchData(); candleSync.resume(); });

// Sync candles for the ACTIVE trades only (forced; just the days after the last stored one, so a few seconds) — to look at today's
// candle between the full runs. The prices and profit on this page refresh when it is done.
const candleSync = useCandleSync(() => fetchData(true));
const activeCount = computed(() => trades.value.filter(t => t.status === 'active').length);

// ─── Showing ─────────────────────────────────────────────────────────

const count = (status) => trades.value.filter(t => t.status === status).length;

function sort(col) {
    if (sortBy.value === col) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    else { sortBy.value = col; sortDir.value = col === 'symbol' ? 'asc' : 'desc'; }
}
const sortIcon = (col) => sortBy.value !== col ? 'bi-arrow-down-up' : (sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down');

const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = trades.value.filter(t => t.status === tab.value && (!q || t.symbol.toLowerCase().includes(q)));
    const dir = sortDir.value === 'asc' ? 1 : -1;
    // nulls (no price yet) always sink to the bottom
    return list.sort((a, b) => {
        const x = a[sortBy.value], y = b[sortBy.value];
        if (x === null || x === undefined) return (y === null || y === undefined) ? 0 : 1;
        if (y === null || y === undefined) return -1;
        return (typeof x === 'string' ? x.localeCompare(y) : x - y) * dir;
    });
});

const stats = computed(() => tab.value === 'active' ? summary.value : archivedSummary.value);
const money = (v) => v === null || v === undefined ? '—' : (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);

// ─── Forms ───────────────────────────────────────────────────────────

const showTradeForm = ref(false);
const showAction    = ref(false);
const actionTrade   = ref(null);
const actionEdit    = ref(null);
const actionSide    = ref('buy');

function openAction(trade, side = 'buy', action = null) {
    actionTrade.value = trade;
    actionEdit.value  = action;
    actionSide.value  = side;
    showAction.value  = true;
    open.value[trade.id] = true;
}

function onTradeSaved(res) {
    tab.value = 'active';
    fetchData().then(() => { if (res && res.id) open.value[res.id] = true; });
}

// ─── Trade actions ───────────────────────────────────────────────────

function setStatus(t, status) {
    DBService.postData('/api/trades/status', { id: t.id, status }).then((data) => {
        if (!data.success) { alert(data.message); return; }
        fetchData();
    });
}

function archive(t) {
    if (t.qty > 0 && !confirm(`${t.symbol} still has ${fmtNum(t.qty)} shares held.\n\nArchive it anyway? You can bring it back to Active any time.`)) return;
    setStatus(t, 'archived');
}

function removeTrade(t) {
    if (!confirm(`Delete the ${t.symbol} trade and its ${t.actions.length} buy/sell entr${t.actions.length === 1 ? 'y' : 'ies'}?\n\nThis cannot be undone. (Archive it instead to keep the history.)`)) return;
    DBService.postData('/api/trades/delete', { id: t.id }).then((data) => {
        if (!data.success) { alert(data.message); return; }
        fetchData();
    });
}

function removeAction(t, a) {
    if (!confirm(`Delete this ${a.side} of ${fmtNum(a.qty)} ${t.symbol} at ${fmtPrice(a.price)} (${fmtDate(a.action_date)})?`)) return;
    DBService.postData('/api/trades/action/delete', { id: a.id }).then((data) => {
        if (!data.success) { alert(data.message); return; }
        fetchData();
    });
}

// ─── Chart popup ─────────────────────────────────────────────────────

const showChartPopup = ref(false);
const popupItems     = ref([]);
const popupStart     = ref(0);

function openChart(row) {
    popupItems.value = rows.value.map(r => ({ exchange: r.exchange, symbol: r.symbol }));
    popupStart.value = Math.max(0, rows.value.findIndex(r => r.id === row.id));
    showChartPopup.value = true;
}

const COLS = 11;
</script>

<template>
    <PageHeader
        title="Trades"
        subtitle="Your actual trades — every buy and sell, with profit and loss on the live price"
        placeholder="Search symbol…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="candleSync.syncing.value || !activeCount" :title="'Fetch the latest candles (today\'s too) for the ' + activeCount + ' symbol' + (activeCount === 1 ? '' : 's') + ' of your active trades only'" @click="candleSync.start('/api/trades/sync')">
                <span v-if="candleSync.syncing.value" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-cloud-download"></i>
                {{ candleSync.syncing.value ? 'Syncing…' : 'Sync candles' }}
            </button>
            <button class="btn-secondary-sm" :disabled="loading" @click="fetchData(true)">
                <span v-if="loading" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-arrow-clockwise"></i>
                Refresh LTP
            </button>
            <button class="btn-new-filing" @click="showTradeForm = true">
                <i class="bi bi-plus-lg me-1"></i> Add Trade
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="candleSync.note.value" class="pf-alert" :class="candleSync.noteOk.value ? 'pf-alert-ok' : 'pf-alert-bad'">
            <i class="bi" :class="candleSync.noteOk.value ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
            <div class="flex-grow-1">Candle sync (active trades): {{ candleSync.note.value }}</div>
            <button class="tbl-icon-btn" @click="candleSync.note.value = ''"><i class="bi bi-x-lg"></i></button>
        </div>
        <div v-if="ltpError" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Live prices unavailable — using the last stored close where there is one. {{ ltpError }}
        </div>
        <div v-if="stats.no_price" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ stats.no_price }} trade{{ stats.no_price === 1 ? ' has' : 's have' }} no price (no live quote and no stored candles), so {{ stats.no_price === 1 ? 'its' : 'their' }} unrealised profit is left out of the totals.
        </div>

        <!-- Summary -->
        <div class="pf-summary">
            <div class="pf-stat">
                <div class="pf-stat-label">{{ tab === 'active' ? 'Invested (held)' : 'Still held' }}</div>
                <div class="pf-stat-value">₹ {{ fmtNum(stats.invested) }}</div>
                <div class="pf-stat-sub">{{ stats.holding || 0 }} of {{ stats.trades || 0 }} trade{{ stats.trades === 1 ? '' : 's' }} holding shares</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Current value</div>
                <div class="pf-stat-value">₹ {{ fmtNum(stats.value) }}</div>
                <div class="pf-stat-sub">of what is held</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Unrealised P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(stats.unrealised)">{{ money(stats.unrealised) }}</div>
                <div class="pf-stat-sub">on what is still held</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Realised P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(stats.realised)">{{ money(stats.realised) }}</div>
                <div class="pf-stat-sub">from sells, after charges</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Total P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(stats.total)">{{ money(stats.total) }}</div>
                <div class="pf-stat-sub">realised + unrealised</div>
            </div>
        </div>

        <div class="card-panel">

            <div class="pf-tabs">
                <button class="pf-tab" :class="{ active: tab === 'active' }" @click="tab = 'active'">Active <span class="pf-tab-count">{{ count('active') }}</span></button>
                <button class="pf-tab" :class="{ active: tab === 'archived' }" @click="tab = 'archived'">Archived <span class="pf-tab-count">{{ count('archived') }}</span></button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:34px"></th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'symbol' }" style="min-width:140px" @click="sort('symbol')">Symbol <i class="bi" :class="sortIcon('symbol')"></i></th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'qty' }" title="Shares still held" @click="sort('qty')">Qty <i class="bi" :class="sortIcon('qty')"></i></th>
                            <th class="text-end" title="Average cost of the shares still held">Avg cost</th>
                            <th class="text-end">LTP</th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'invested' }" title="Average cost × quantity held" @click="sort('invested')">Invested <i class="bi" :class="sortIcon('invested')"></i></th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'unrealised' }" title="(LTP − average cost) × quantity held" @click="sort('unrealised')">Unrealised <i class="bi" :class="sortIcon('unrealised')"></i></th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'realised' }" title="Profit on what was sold, after all charges" @click="sort('realised')">Realised <i class="bi" :class="sortIcon('realised')"></i></th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'total' }" title="Realised + unrealised, and as a % of everything bought" @click="sort('total')">Total P&amp;L <i class="bi" :class="sortIcon('total')"></i></th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!loaded">
                            <td :colspan="COLS" class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td :colspan="COLS" class="text-center py-5 text-muted">
                                <template v-if="search">No trades match your search</template>
                                <template v-else-if="tab === 'active'">No active trades. Use “Add Trade”, then record your buys and sells.</template>
                                <template v-else>Nothing archived yet.</template>
                            </td>
                        </tr>

                        <template v-else v-for="t in rows" :key="t.id">
                            <tr>
                                <td>
                                    <button class="tbl-icon-btn" :title="open[t.id] ? 'Hide the buys and sells' : 'Show the buys and sells'" @click="open[t.id] = !open[t.id]">
                                        <i class="bi" :class="open[t.id] ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-link p-0 cell-primary mono text-start" style="text-decoration:none" title="Open the charts" @click="openChart(t)">{{ t.symbol }}</button>
                                    <div class="cell-secondary mono" :class="{ 'text-primary': t.exchange !== 'NSE' }">{{ t.exchange }}<template v-if="t.first_date"> · since {{ fmtDate(t.first_date) }}</template></div>
                                    <div v-if="t.notes" class="cell-secondary" style="white-space:normal;max-width:220px">{{ t.notes }}</div>
                                </td>
                                <td class="text-num">{{ t.qty ? fmtNum(t.qty) : '—' }}</td>
                                <td class="text-num">{{ t.qty ? fmtPrice(t.avg_price) : '—' }}</td>
                                <td class="text-num">
                                    <template v-if="!t.qty">—</template>
                                    <span v-else-if="t.price === null" class="status-chip chip-red" title="No live quote and no stored candles for this stock">no price</span>
                                    <template v-else>
                                        {{ fmtPrice(t.price) }}
                                        <div v-if="t.price_source === 'close'" class="cell-secondary" style="margin:0" title="No live quote — this is the last stored close">last close</div>
                                    </template>
                                </td>
                                <td class="text-num">{{ t.qty ? '₹' + fmtNum(t.invested) : '—' }}</td>
                                <td class="text-num">
                                    <template v-if="t.qty && t.unrealised !== null">
                                        <span :class="pnlClass(t.unrealised)">{{ money(t.unrealised) }}</span>
                                        <div class="cell-secondary" :class="pnlClass(t.unrealised_pct)" style="margin:0">{{ fmtPct(t.unrealised_pct) }}</div>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-num">
                                    <span v-if="t.sold_qty || t.charges" :class="pnlClass(t.realised)" :title="`${money(t.realised_gross)} on sells, less ₹${fmtNum(t.charges, 2)} charges`">{{ money(t.realised) }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-num">
                                    <template v-if="t.total !== null && t.actions.length">
                                        <span class="fw-semibold" :class="pnlClass(t.total)">{{ money(t.total) }}</span>
                                        <div v-if="t.return_pct !== null" class="cell-secondary" :class="pnlClass(t.return_pct)" style="margin:0" title="Total P&L as a % of everything bought">{{ fmtPct(t.return_pct) }}</div>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span v-if="!t.actions.length" class="status-chip chip-neutral">No buys yet</span>
                                    <span v-else-if="t.qty === 0" class="status-chip chip-neutral" title="Everything bought has been sold">Fully sold</span>
                                    <span v-else-if="t.sold_qty" class="status-chip chip-amber" title="Part of it has been sold">Part sold</span>
                                    <span v-else class="status-chip chip-green">Holding</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <template v-if="t.status === 'active'">
                                        <button class="tbl-icon-btn me-1" title="Record a buy" @click="openAction(t, 'buy')"><i class="bi bi-plus-lg"></i></button>
                                        <button class="tbl-icon-btn me-1" :disabled="!t.qty" :title="t.qty ? 'Record a sell' : 'Nothing held to sell'" @click="openAction(t, 'sell')"><i class="bi bi-dash-lg"></i></button>
                                    </template>
                                    <button class="tbl-icon-btn me-1" title="Open the charts" @click="openChart(t)"><i class="bi bi-bar-chart-line"></i></button>
                                    <button v-if="t.status === 'active'" class="tbl-icon-btn me-1" :class="{ 'fib-on': t.qty === 0 && t.actions.length }" :title="t.qty === 0 && t.actions.length ? 'Fully sold — archive it' : 'Archive'" @click="archive(t)"><i class="bi bi-archive"></i></button>
                                    <button v-else class="tbl-icon-btn me-1" title="Bring back to Active" @click="setStatus(t, 'active')"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    <button class="tbl-icon-btn tbl-icon-btn-danger" title="Delete the trade" @click="removeTrade(t)"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>

                            <!-- the buys and sells -->
                            <tr v-if="open[t.id]" class="trade-detail-row">
                                <td></td>
                                <td :colspan="COLS - 1">
                                    <table v-if="t.actions.length" class="trade-actions">
                                        <thead>
                                            <tr><th>Date</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th><th class="text-end">Charges</th><th class="text-end">Then holding</th><th class="text-end">Realised</th><th>Notes</th><th></th></tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="a in t.actions" :key="a.id">
                                                <td class="text-nowrap">{{ fmtDate(a.action_date) }}</td>
                                                <td><span class="status-chip" :class="a.side === 'buy' ? 'chip-green' : 'chip-red'" style="text-transform:uppercase">{{ a.side }}</span></td>
                                                <td class="text-num">{{ fmtNum(a.qty) }}</td>
                                                <td class="text-num">{{ fmtPrice(a.price) }}</td>
                                                <td class="text-num">₹{{ fmtNum(a.qty * a.price) }}</td>
                                                <td class="text-num">{{ a.charges ? fmtPrice(a.charges) : '—' }}</td>
                                                <td class="text-num text-muted">{{ a.running_qty ? `${fmtNum(a.running_qty)} @ ${fmtPrice(a.running_avg)}` : 'flat' }}</td>
                                                <td class="text-num"><span v-if="a.realised !== null" :class="pnlClass(a.realised)">{{ money(a.realised) }}</span><span v-else class="text-muted">—</span></td>
                                                <td class="text-muted" style="max-width:200px;white-space:normal">{{ a.notes }}</td>
                                                <td class="text-end text-nowrap">
                                                    <button class="tbl-icon-btn me-1" title="Edit" @click="openAction(t, a.side, a)"><i class="bi bi-pencil"></i></button>
                                                    <button class="tbl-icon-btn tbl-icon-btn-danger" title="Delete" @click="removeAction(t, a)"><i class="bi bi-trash"></i></button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div v-else class="text-muted" style="font-size:13px">No buys or sells recorded yet.</div>

                                    <div v-if="t.status === 'active'" class="mt-2 d-flex gap-2">
                                        <button class="btn-secondary-sm" @click="openAction(t, 'buy')"><i class="bi bi-plus-lg"></i> Buy</button>
                                        <button class="btn-secondary-sm" :disabled="!t.qty" @click="openAction(t, 'sell')"><i class="bi bi-dash-lg"></i> Sell</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <TradeForm :show="showTradeForm" @close="showTradeForm = false" @saved="onTradeSaved" />
    <ActionForm :show="showAction" :trade="actionTrade" :action="actionEdit" :side="actionSide" @close="showAction = false" @saved="fetchData()" />
    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" />
</template>
