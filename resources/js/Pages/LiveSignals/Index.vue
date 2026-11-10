<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtDate, pnlClass } from '@/Service/Utils/Format.js';
import DayChart from '@/Includes/LiveSignals/DayChart.vue';

// What live trading is doing: the alerts as they arrive and what the engine did with each, what we hold, and every order.
// Read-only: the engine itself is App\Services\Live (webhook → signals → orders, and the once-a-minute check).
const overview  = ref(null);
const signals   = ref([]);
const positions = ref({ open: [], closed: [] });
const orders    = ref([]);
const tab       = ref('signals');          // signals | positions | orders
const auto      = ref(true);
const checking  = ref(false);
const banner    = ref(null);
let timer = null;

const money = (v) => v === null || v === undefined ? '—' : (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);
const pct   = (v) => v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + fmtNum(v, 2) + '%';

// '2026-11-10 09:58:00' → '09:58:00' for today, '10 Nov 09:58' otherwise
function ts(s) {
    if (!s) return '—';
    const today = overview.value ? overview.value.now.slice(0, 10) : '';
    return s.slice(0, 10) === today ? s.slice(11, 19) : fmtDate(s).slice(0, 6) + ' ' + s.slice(11, 16);
}

const SIGNAL_CHIP   = { new: 'chip-amber', working: 'chip-amber', done: 'chip-green', skipped: 'chip-neutral', failed: 'chip-red' };
const POSITION_CHIP = { pending: 'chip-amber', open: 'chip-green', closed: 'chip-neutral', cancelled: 'chip-neutral', failed: 'chip-red' };
const ORDER_CHIP    = { sending: 'chip-amber', open: 'chip-amber', complete: 'chip-green', rejected: 'chip-red', cancelled: 'chip-neutral', unknown: 'chip-red' };
const EXIT_CHIP     = { stop: 'chip-red', target: 'chip-green', time_exit: 'chip-neutral' };
const EXIT_LABEL    = { stop: 'stop loss', target: 'target', time_exit: 'time exit' };

function loadOverview() {
    return DBService.postData('/api/live/overview', {}).then((d) => { if (d.success) overview.value = d; });
}

function loadTab() {
    const t = tab.value;
    if (t === 'signals')   return DBService.postData('/api/live/signals', {}).then((d) => { if (d.success) signals.value = d.signals; });
    if (t === 'positions') return DBService.postData('/api/live/positions', {}).then((d) => { if (d.success) positions.value = d; });
    return DBService.postData('/api/live/orders', {}).then((d) => { if (d.success) orders.value = d.orders; });
}

// the day chart of a stock (5-minute candles with the alerts and trades on it)
const chart = ref({ show: false, symbol: '', date: '' });
function openChart(symbol, date) {
    chart.value = { show: true, symbol, date: (date || '').slice(0, 10) || (overview.value ? overview.value.now.slice(0, 10) : '') };
}

function refresh() { return Promise.all([loadOverview(), loadTab()]); }

function setTab(t) { tab.value = t; loadTab(); }

onMounted(() => {
    refresh();
    timer = setInterval(() => { if (auto.value && !document.hidden) refresh(); }, 10000);
});
onBeforeUnmount(() => clearInterval(timer));

// run the once-a-minute check right now (the cron does it every minute while the market is open)
function checkNow() {
    checking.value = true;
    banner.value = null;
    DBService.postData('/api/live/tick', {}).then((d) => {
        checking.value = false;
        banner.value = { ok: d.success, bad: !d.success, message: typeof d.message === 'string' ? d.message : 'The check failed' };
        refresh();
    });
}

const tickAge = computed(() => {
    const o = overview.value;
    if (!o || !o.last_tick) return null;
    const a = new Date(o.now.replace(' ', 'T')).getTime(), b = new Date(o.last_tick.replace(' ', 'T')).getTime();
    return Math.max(0, Math.round((a - b) / 1000));
});
const tickText = computed(() => tickAge.value === null ? 'never' : (tickAge.value < 90 ? tickAge.value + ' s ago' : Math.round(tickAge.value / 60) + ' min ago'));
const today = (k) => overview.value && overview.value.signals_today[k] ? overview.value.signals_today[k] : 0;
</script>

<template>
    <PageHeader title="Live Signals" subtitle="Alerts as they arrive, what the engine did with them, what we hold" :show-search="false">
        <template #actions>
            <label class="sc-check" style="margin:0 6px 0 0"><input v-model="auto" type="checkbox" /> Refresh every 10 s</label>
            <button class="btn-secondary-sm" :disabled="checking" @click="checkNow" title="Run the once-a-minute check now: act on waiting signals, update orders, look at stops, targets and exits">
                <span v-if="checking" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-arrow-repeat"></i> Check now
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="banner" class="pf-alert zt-result" :class="{ 'pf-alert-ok': banner.ok, 'pf-alert-bad': banner.bad }">
            <i class="bi" :class="banner.bad ? 'bi-x-circle-fill' : 'bi-check-circle-fill'"></i>
            <div class="flex-grow-1">{{ banner.message }}</div>
            <button class="tbl-icon-btn" @click="banner = null"><i class="bi bi-x-lg"></i></button>
        </div>

        <div v-if="!overview" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</div>

        <template v-else>
            <div v-if="overview.attention.length" class="ls-attn"><div v-for="(a, i) in overview.attention" :key="i"><i class="bi bi-exclamation-triangle-fill me-1"></i> {{ a }}</div></div>
            <div v-if="!overview.webhook_ready" class="ls-attn"><div><i class="bi bi-exclamation-triangle-fill me-1"></i> The webhook has no secret yet, so ChartInk alerts are refused. Set CHARTINK_WEBHOOK_SECRET in .env (see Live Config).</div></div>

            <div class="pf-summary">
                <div class="pf-stat">
                    <div class="pf-stat-label">Mode</div>
                    <div class="pf-stat-value" style="font-size:16px">
                        <span class="status-chip" :class="overview.config.mode === 'live' ? 'chip-red' : 'chip-neutral'">{{ overview.config.mode === 'live' ? 'LIVE' : 'Dry run' }}</span>
                        <span class="status-chip ms-1" :class="overview.config.enabled ? 'chip-green' : 'chip-amber'">{{ overview.config.enabled ? 'Trading on' : 'Trading off' }}</span>
                    </div>
                    <div class="pf-stat-sub">{{ overview.config.alert_names.join(', ') }} · <a href="/live-config">settings</a></div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Open positions</div>
                    <div class="pf-stat-value">{{ overview.open_positions }}</div>
                    <div class="pf-stat-sub">{{ overview.pending_positions ? overview.pending_positions + ' waiting to fill · ' : '' }}₹{{ fmtNum(overview.capital_held) }} held</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Unrealised</div>
                    <div class="pf-stat-value" :class="pnlClass(overview.unrealised)">{{ money(overview.unrealised) }}</div>
                    <div class="pf-stat-sub">on what is held, at the last price</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Realised today</div>
                    <div class="pf-stat-value" :class="pnlClass(overview.realised_today)">{{ money(overview.realised_today) }}</div>
                    <div class="pf-stat-sub">before charges</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Alerts today</div>
                    <div class="pf-stat-value">{{ today('done') + today('skipped') + today('failed') + today('new') + today('working') }}</div>
                    <div class="pf-stat-sub">{{ today('done') }} traded · {{ today('skipped') }} skipped<template v-if="today('failed')"> · <span class="pnl-neg">{{ today('failed') }} failed</span></template></div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Last check</div>
                    <div class="pf-stat-value" style="font-size:16px">{{ tickText }}</div>
                    <div class="pf-stat-sub">market {{ overview.market_open ? 'open' : 'closed' }}</div>
                </div>
            </div>

            <div class="card-panel">
                <div class="pf-tabs">
                    <button class="pf-tab" :class="{ active: tab === 'signals' }" @click="setTab('signals')">Signals</button>
                    <button class="pf-tab" :class="{ active: tab === 'positions' }" @click="setTab('positions')">Positions <span class="pf-tab-count">{{ overview.open_positions + overview.pending_positions }}</span></button>
                    <button class="pf-tab" :class="{ active: tab === 'orders' }" @click="setTab('orders')">Orders</button>
                </div>

                <!-- ── signals ── -->
                <div v-if="tab === 'signals'" class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Received</th><th>Stock</th><th>Alert</th><th class="text-end">Alert price</th><th>What happened</th><th>Why</th></tr></thead>
                        <tbody>
                            <tr v-if="!signals.length"><td colspan="6" class="text-center py-5 text-muted">No alerts yet. When ChartInk calls the webhook they appear here within a second.</td></tr>
                            <tr v-for="s in signals" :key="s.id">
                                <td class="text-nowrap mono">{{ ts(s.received_at) }}<div class="cell-secondary" style="margin:0" title="The time ChartInk gave the alert">fired {{ s.triggered_at.slice(11, 16) }}</div></td>
                                <td class="cell-primary mono">{{ s.symbol }}<button class="ls-chart-btn" title="View the chart of the day" @click="openChart(s.symbol, s.triggered_at)"><i class="bi bi-graph-up"></i></button></td>
                                <td class="text-muted">{{ s.alert_name }}</td>
                                <td class="text-num">{{ s.trigger_price ? fmtPrice(s.trigger_price) : '—' }}</td>
                                <td>
                                    <span class="status-chip" :class="SIGNAL_CHIP[s.status]">{{ s.status === 'done' ? (s.action === 'average' ? 'averaged' : 'bought') : s.status }}</span>
                                </td>
                                <td class="ls-reason">{{ s.reason || '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── positions ── -->
                <div v-else-if="tab === 'positions'" class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Stock</th><th>Status</th><th class="text-end">Qty</th><th class="text-end">Avg price</th><th class="text-end">Last price</th><th class="text-end">Unrealised</th><th class="text-end">Averages</th><th>Sell on / after</th><th>Bought</th></tr></thead>
                        <tbody>
                            <tr v-if="!positions.open.length"><td colspan="9" class="text-center py-4 text-muted">Nothing held right now.</td></tr>
                            <tr v-for="p in positions.open" :key="p.id">
                                <td class="cell-primary mono">{{ p.symbol }}<button class="ls-chart-btn" title="View the chart of the day" @click="openChart(p.symbol, '')"><i class="bi bi-graph-up"></i></button><span v-if="p.mode === 'dry_run'" class="status-chip chip-neutral ms-2" title="Simulated: no real order">dry run</span></td>
                                <td><span class="status-chip" :class="POSITION_CHIP[p.status]">{{ p.status === 'pending' ? 'waiting to fill' : p.status }}</span></td>
                                <td class="text-num">{{ p.qty ? fmtNum(p.qty) : '—' }}</td>
                                <td class="text-num">{{ p.qty ? fmtPrice(p.avg_price) : '—' }}</td>
                                <td class="text-num">{{ p.last_price ? fmtPrice(p.last_price) : '—' }}</td>
                                <td class="text-num"><template v-if="p.unrealised !== null"><span :class="pnlClass(p.unrealised)">{{ money(p.unrealised) }}</span><div class="cell-secondary" :class="pnlClass(p.unrealised_pct)" style="margin:0">{{ pct(p.unrealised_pct) }}</div></template><span v-else class="text-muted">—</span></td>
                                <td class="text-num">{{ p.adds }}</td>
                                <td class="text-nowrap">{{ p.hold_until ? fmtDate(p.hold_until) : '—' }}</td>
                                <td class="text-nowrap mono">{{ ts(p.opened_at || p.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="sc-sub" style="padding:14px 16px 6px">Closed</div>
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Stock</th><th>Bought</th><th>Sold</th><th class="text-end">Qty sold</th><th class="text-end">Avg buy</th><th class="text-end">Sold at</th><th>Why</th><th class="text-end">P&amp;L</th></tr></thead>
                        <tbody>
                            <tr v-if="!positions.closed.length"><td colspan="8" class="text-center py-4 text-muted">Nothing closed yet.</td></tr>
                            <tr v-for="p in positions.closed" :key="p.id">
                                <td class="cell-primary mono">{{ p.symbol }}<button class="ls-chart-btn" title="View the chart of the day it was bought" @click="openChart(p.symbol, p.opened_at || p.created_at)"><i class="bi bi-graph-up"></i></button><span v-if="p.mode === 'dry_run'" class="status-chip chip-neutral ms-2">dry run</span></td>
                                <td class="text-nowrap mono">{{ ts(p.opened_at || p.created_at) }}</td>
                                <td class="text-nowrap mono">{{ ts(p.closed_at) }}</td>
                                <td class="text-num">{{ p.status === 'closed' ? fmtNum(p.sold_qty) : '—' }}</td>
                                <td class="text-num">{{ p.status === 'closed' ? fmtPrice(p.avg_price) : '—' }}</td>
                                <td class="text-num">{{ p.exit_price ? fmtPrice(p.exit_price) : '—' }}</td>
                                <td><span v-if="p.status === 'closed'" class="status-chip" :class="EXIT_CHIP[p.exit_reason]">{{ EXIT_LABEL[p.exit_reason] || p.exit_reason }}</span><span v-else class="status-chip" :class="POSITION_CHIP[p.status]">{{ p.status === 'cancelled' ? 'never filled' : p.status }}</span></td>
                                <td class="text-num"><template v-if="p.pnl !== null"><span class="fw-semibold" :class="pnlClass(p.pnl)">{{ money(p.pnl) }}</span><div class="cell-secondary" :class="pnlClass(p.pnl_pct)" style="margin:0">{{ pct(p.pnl_pct) }}</div></template><span v-else class="text-muted">—</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── orders ── -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Placed</th><th>Stock</th><th>Side</th><th>For</th><th class="text-end">Qty</th><th>Type</th><th class="text-end">Price</th><th>Status</th><th class="text-end">Filled</th><th>Note</th></tr></thead>
                        <tbody>
                            <tr v-if="!orders.length"><td colspan="10" class="text-center py-5 text-muted">No orders yet.</td></tr>
                            <tr v-for="o in orders" :key="o.id">
                                <td class="text-nowrap mono">{{ ts(o.placed_at) }}</td>
                                <td class="cell-primary mono">{{ o.symbol }}<button class="ls-chart-btn" title="View the chart of the day" @click="openChart(o.symbol, o.placed_at)"><i class="bi bi-graph-up"></i></button><span v-if="o.mode === 'dry_run'" class="status-chip chip-neutral ms-2">dry run</span></td>
                                <td><span class="status-chip" :class="o.side === 'buy' ? 'chip-green' : 'chip-red'" style="text-transform:uppercase">{{ o.side }}</span></td>
                                <td class="text-muted">{{ o.kind.replace('_', ' ') }}</td>
                                <td class="text-num">{{ fmtNum(o.qty) }}</td>
                                <td class="text-muted">{{ o.order_type }}</td>
                                <td class="text-num">{{ o.price ? fmtPrice(o.price) : '—' }}</td>
                                <td><span class="status-chip" :class="ORDER_CHIP[o.status]">{{ o.status }}</span></td>
                                <td class="text-num">{{ o.filled_qty ? fmtNum(o.filled_qty) + ' @ ' + fmtPrice(o.filled_price) : '—' }}</td>
                                <td class="ls-reason">{{ o.message }}<span v-if="o.broker_order_id" class="mono"> · {{ o.broker_order_id }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <DayChart :show="chart.show" :symbol="chart.symbol" :date="chart.date" @close="chart.show = false" />
    </div>
</template>
