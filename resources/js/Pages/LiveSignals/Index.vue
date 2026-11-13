<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtDate, pnlClass } from '@/Service/Utils/Format.js';
import DayChart from '@/Includes/LiveSignals/DayChart.vue';

// What live trading is doing: the alerts as they arrive and what the engine did with each, what we hold, and every order.
// Read-only: the engine itself is App\Services\Live (webhook → signals → orders, and the once-a-minute check).
const overview  = ref(null);
const signals   = ref([]);                  // the signals that count (the ignored repeats are kept apart)
const ignoredRows  = ref([]);               // ignored by the engine: the stock is already held, or an earlier signal for it is still waiting — loaded when its tab is opened
const ignoredCount = ref(0);
// duplicates live in their own table (a stock that already had a signal that day, repeated by the same alert or another): loaded when the tab is opened
const dupRows   = ref([]);
const dupCount  = ref(0);                  // all of them
const dupTotal  = ref(0);                  // the ones matching the day / alert chosen
const dupAlerts = ref([]);
const dupDays   = ref({});                 // 'Y-m-d' => how many
const dupDay    = ref('all');
const dupAlert  = ref('all');
const deletingDups = ref(false);
const cleaning = ref(false);
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

// where a signal stands (status) and how it was rated (verdict)
const SIGNAL_CHIP   = { new: 'chip-amber', working: 'chip-amber', watching: 'chip-amber', enter: 'chip-green', done: 'chip-green', skipped: 'chip-neutral', ignored: 'chip-neutral', expired: 'chip-neutral', failed: 'chip-red' };
const SIGNAL_LABEL  = { new: 'waiting for its candle', working: 'checking…', watching: 'watching', enter: 'can enter · waiting for room', done: 'bought', skipped: 'skipped', ignored: 'ignored', expired: 'expired', failed: 'failed' };
const VERDICT       = { enter: ['chip-green', 'Can enter'], watch: ['chip-amber', 'Watch'], skip: ['chip-amber', 'Weak'], nodata: ['chip-neutral', 'No data'] };
const FILTERS       = [['all', 'All'], ['waiting', 'Waiting'], ['bought', 'Bought'], ['skipped', 'Skipped'], ['ignored', 'Ignored'], ['duplicates', 'Duplicates'], ['ended', 'Expired / failed']];
const GROUP         = { new: 'waiting', working: 'waiting', watching: 'waiting', enter: 'waiting', done: 'bought', skipped: 'skipped', ignored: 'ignored', expired: 'ended', failed: 'ended' };
const POSITION_CHIP = { pending: 'chip-amber', open: 'chip-green', closed: 'chip-neutral', cancelled: 'chip-neutral', failed: 'chip-red' };
const ORDER_CHIP    = { sending: 'chip-amber', open: 'chip-amber', complete: 'chip-green', rejected: 'chip-red', cancelled: 'chip-neutral', unknown: 'chip-red' };
const EXIT_CHIP     = { stop: 'chip-red', target: 'chip-green', time_exit: 'chip-neutral', manual: 'chip-amber' };
const EXIT_LABEL    = { stop: 'stop loss', target: 'target', time_exit: 'time exit', manual: 'sold by hand' };
const FAIL_LABEL    = { green: 'not green', day_chg: 'up too much', from_low: 'far off the low', vwap: 'VWAP', rsi_hi: 'RSI high', rsi_lo: 'RSI low', volume: 'low volume', window: 'time', d_trend: 'daily trend', d_chg5: '5-day run', d_rsi: 'daily RSI', m_chg1: 'month run', drift: 'price ran' };

function loadOverview() {
    return DBService.postData('/api/live/overview', {}).then((d) => { if (d.success) overview.value = d; });
}

function loadTab() {
    const t = tab.value;
    if (t === 'signals')   return Promise.all([
        DBService.postData('/api/live/signals', {}).then((d) => { if (d.success) { signals.value = d.signals; ignoredCount.value = d.ignored_count; dupCount.value = d.duplicates_count; Object.keys(timeline.value).forEach((id) => loadChecks(id)); } }),
        filter.value === 'ignored' ? loadIgnored() : null,
        filter.value === 'duplicates' ? loadDuplicates() : null,
    ]);
    if (t === 'positions') return DBService.postData('/api/live/positions', {}).then((d) => { if (d.success) positions.value = d; });
    return DBService.postData('/api/live/orders', {}).then((d) => { if (d.success) orders.value = d.orders; });
}

// the day chart of a stock (5-minute candles with the alerts and trades on it)
const chart = ref({ show: false, symbol: '', date: '' });
function openChart(symbol, date) {
    chart.value = { show: true, symbol, date: (date || '').slice(0, 10) || (overview.value ? overview.value.now.slice(0, 10) : '') };
}

// signal list: filter, and the status-over-time of one signal
const filter = ref('all');
const alertFilter = ref('all');   // all | an alert name — alerts from different ChartInk scans are recorded side by side
function loadIgnored() {
    return DBService.postData('/api/live/signals', { ignored: 1 }).then((d) => { if (d.success) { ignoredRows.value = d.signals; ignoredCount.value = d.ignored_count; } });
}
function loadDuplicates() {
    return DBService.postData('/api/live/duplicates', { alert: dupAlert.value === 'all' ? '' : dupAlert.value, date: dupDay.value === 'all' ? '' : dupDay.value }).then((d) => {
        if (!d.success) return;
        dupRows.value = d.duplicates; dupTotal.value = d.total; dupCount.value = d.all; dupAlerts.value = d.alerts; dupDays.value = d.days;
        // a day / alert that no longer has duplicates (after a delete) goes back to "all"
        if (dupDay.value !== 'all' && !(dupDay.value in d.days)) { dupDay.value = 'all'; loadDuplicates(); }
        else if (dupAlert.value !== 'all' && !d.alerts.includes(dupAlert.value)) { dupAlert.value = 'all'; loadDuplicates(); }
    });
}
function setFilter(f) {
    filter.value = f;
    if (f === 'ignored') loadIgnored();
    if (f === 'duplicates') loadDuplicates();
}

// repeats already sitting in the Signals list (older data): keep the first signal of each stock per day, move the rest here
function cleanSignals() {
    if (cleaning.value) return;
    cleaning.value = true;
    DBService.postData('/api/live/duplicates/clean', {}).then((d) => {
        if (!d.success) { cleaning.value = false; alert(d.message || 'Could not check'); return; }
        if (!d.moved) {
            cleaning.value = false;
            banner.value = { ok: true, message: 'The Signals list is clean: every stock has one signal per day' + (d.kept_active || d.kept_bought ? ' (' + (d.kept_active + d.kept_bought) + ' repeat(s) left alone because they are live or were bought)' : '') + '.' };
            return;
        }
        const alerts = Object.entries(d.by_alert).map(([a, n]) => a + ': ' + n).join(', ');
        const ok = confirm(d.moved + ' repeat signal(s) in the Signals list will move to Duplicates (' + alerts + ').\n\nFor each stock and day, only the signal triggered FIRST stays in Signals.' + (d.kept_active || d.kept_bought ? '\nLeft alone: ' + d.kept_active + ' live, ' + d.kept_bought + ' bought.' : '') + '\n\nMove them?');
        if (!ok) { cleaning.value = false; return; }
        DBService.postData('/api/live/duplicates/clean', { apply: 1 }).then((r) => {
            cleaning.value = false;
            banner.value = { ok: r.success, bad: !r.success, message: r.message || 'Could not clean' };
            refresh();
        });
    }).catch(() => { cleaning.value = false; });
}

// delete the duplicates shown (every one, or only a day's / an alert's): real signals are never touched
function deleteDuplicates() {
    if (!dupTotal.value || deletingDups.value) return;
    const what = (dupAlert.value === 'all' ? '' : ' from the alert ' + dupAlert.value) + (dupDay.value === 'all' ? '' : ' of ' + fmtDate(dupDay.value));
    if (!confirm('Delete ' + dupTotal.value + ' duplicate signal(s)' + what + '?\n\nThey are repeats of a signal that was already judged (same stock, same day), so nothing is lost for trading. This cannot be undone.')) return;
    deletingDups.value = true;
    DBService.postData('/api/live/duplicates/delete', { alert: dupAlert.value === 'all' ? '' : dupAlert.value, date: dupDay.value === 'all' ? '' : dupDay.value }).then((d) => {
        deletingDups.value = false;
        banner.value = { ok: d.success, bad: !d.success, message: typeof d.message === 'string' ? d.message : 'Could not delete' };
        loadDuplicates();
    });
}
// the alert names seen, with how many signals each (the ignored list is only loaded when opened)
const alertNames = computed(() => [...new Set([...signals.value, ...ignoredRows.value].map((s) => s.alert_name))].sort());
const byAlert = (list) => alertFilter.value === 'all' ? list : list.filter((s) => s.alert_name === alertFilter.value);
const shown = computed(() => filter.value === 'ignored' ? byAlert(ignoredRows.value)
    : byAlert(filter.value === 'all' ? signals.value : signals.value.filter((s) => GROUP[s.status] === filter.value)));
const countOf = (f) => f === 'ignored' ? ignoredCount.value : f === 'duplicates' ? dupCount.value : byAlert(f === 'all' ? signals.value : signals.value.filter((s) => GROUP[s.status] === f)).length;

const timeline = ref({});     // signal id -> checks (open rows)
function loadChecks(id) {
    return DBService.postData('/api/live/signal-checks', { id }).then((d) => { if (d.success) timeline.value = { ...timeline.value, [id]: d.checks }; });
}
function toggleChecks(s) {
    if (timeline.value[s.id]) { const t = { ...timeline.value }; delete t[s.id]; timeline.value = t; return; }
    loadChecks(s.id);
}
const failText = (c) => c.fails.map((f) => FAIL_LABEL[f] || f).join(', ');

// the Exit button
const exiting = ref(null);
function exitPosition(p) {
    const what = p.status === 'pending' ? 'Cancel the buy order for ' + p.symbol + '?' : 'Sell all ' + fmtNum(p.qty) + ' shares of ' + p.symbol + ' now?';
    const real = p.mode === 'live' ? '\n\nThis sends a REAL order to Zerodha.' : '\n\n(Dry run: simulated, nothing is sent to Zerodha.)';
    if (!confirm(what + real)) return;
    exiting.value = p.id;
    DBService.postData('/api/live/positions/exit', { id: p.id }).then((d) => {
        exiting.value = null;
        banner.value = { ok: d.success, bad: !d.success, message: typeof d.message === 'string' ? d.message : 'Could not exit' };
        refresh();
    });
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
const todayAll = computed(() => overview.value ? Object.values(overview.value.signals_today).reduce((a, b) => a + Number(b), 0) : 0);
const todayWaiting = computed(() => today('new') + today('working') + today('watching') + today('enter'));
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
            <div v-if="!overview.webhook_ready" class="ls-attn"><div><i class="bi bi-exclamation-triangle-fill me-1"></i> Live mode needs a webhook secret, so ChartInk alerts are refused. Set CHARTINK_WEBHOOK_SECRET in .env (see Live Config).</div></div>

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
                    <div class="pf-stat-sub">{{ overview.open_slots }} of {{ overview.max_positions }} slots free · {{ overview.pending_positions ? overview.pending_positions + ' waiting to fill · ' : '' }}₹{{ fmtNum(overview.capital_held) }} held</div>
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
                    <div class="pf-stat-value">{{ todayAll }}</div>
                    <div class="pf-stat-sub">{{ today('done') }} bought · {{ todayWaiting }} waiting · {{ today('skipped') }} skipped · {{ today('ignored') }} ignored<template v-if="overview.duplicates_today"> · {{ overview.duplicates_today }} duplicates</template><template v-if="today('failed')"> · <span class="pnl-neg">{{ today('failed') }} failed</span></template></div>
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
                <div v-if="tab === 'signals'">
                    <div class="ls-filters">
                        <button v-for="f in FILTERS" :key="f[0]" class="pf-tab" :class="{ active: filter === f[0] }" style="padding:4px 10px" @click="setFilter(f[0])">{{ f[1] }} <span class="pf-tab-count">{{ countOf(f[0]) }}</span></button>
                        <select v-if="alertNames.length > 1 && filter !== 'duplicates'" v-model="alertFilter" class="form-select form-select-sm ms-auto" style="width:auto" title="Show the signals of one alert (one ChartInk scan) only">
                            <option value="all">All alerts</option>
                            <option v-for="a in alertNames" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>

                    <!-- ── duplicates: kept apart, deletable ── -->
                    <div v-if="filter === 'duplicates'">
                        <div class="ls-filters" style="padding-top:0">
                            <span class="text-muted small">A stock that already had a signal that day, sent again by the same alert or by another one. They never reach the engine.</span>
                            <select v-model="dupDay" class="form-select form-select-sm ms-auto" style="width:auto" title="Show one day's duplicates" @change="loadDuplicates">
                                <option value="all">All days</option>
                                <option v-for="(n, d) in dupDays" :key="d" :value="d">{{ fmtDate(d) }} · {{ n }}</option>
                            </select>
                            <select v-model="dupAlert" class="form-select form-select-sm" style="width:auto" title="Show the duplicates of one alert" @change="loadDuplicates">
                                <option value="all">All alerts</option>
                                <option v-for="a in dupAlerts" :key="a" :value="a">{{ a }}</option>
                            </select>
                            <button class="btn-secondary-sm" :disabled="cleaning" title="Move repeats that are still in the Signals list here: only the signal triggered first for each stock and day stays" @click="cleanSignals">
                                <span v-if="cleaning" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-funnel"></i> Clean up Signals
                            </button>
                            <button class="btn-secondary-sm ls-del-btn" :disabled="!dupTotal || deletingDups" title="Delete the duplicates shown (every one when no day / alert is chosen)" @click="deleteDuplicates">
                                <span v-if="deletingDups" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-trash"></i> Delete duplicates<span v-if="dupTotal"> ({{ dupTotal }})</span>
                            </button>
                        </div>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Received</th><th>Stock</th><th>Alert</th><th class="text-end">Alert price</th><th>Repeats</th></tr></thead>
                            <tbody>
                                <tr v-if="!dupRows.length"><td colspan="5" class="text-center py-5 text-muted">No duplicates{{ dupCount ? ' for this choice' : ' yet' }}. A stock that is sent again the same day while its first signal is still being watched, was bought or was judged Skip is kept here.</td></tr>
                                <tr v-for="d in dupRows" :key="d.id">
                                    <td class="text-nowrap mono">{{ ts(d.received_at) }}<div class="cell-secondary" style="margin:0">fired {{ d.triggered_at.slice(11, 16) }}</div></td>
                                    <td class="cell-primary mono">{{ d.symbol }}<span class="ls-ex" :class="{ 'ls-ex-bse': d.exchange === 'BSE' }">{{ d.exchange }}</span>
                                        <div v-if="d.sector || d.market_cap" class="cell-secondary" style="margin:0">{{ [d.sector, d.market_cap].filter(Boolean).join(' · ') }}</div></td>
                                    <td class="text-muted">{{ d.alert_name }}<div v-if="d.scan_name" class="cell-secondary" style="margin:0">{{ d.scan_name }}</div></td>
                                    <td class="text-num">{{ d.trigger_price ? fmtPrice(d.trigger_price) : '—' }}<div v-if="d.alert_rsi !== null" class="cell-secondary" style="margin:0">RSI {{ fmtNum(d.alert_rsi, 1) }}</div></td>
                                    <td class="ls-reason">{{ d.reason }}<span v-if="d.first_alert && d.first_alert !== d.alert_name" class="status-chip chip-amber ms-1" title="Sent by a different alert than the first signal">other alert</span></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <div v-else class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th style="width:28px"></th><th>Received</th><th>Stock</th><th>Alert</th><th class="text-end">Alert price</th><th>Rated</th><th>Status</th><th>Why</th></tr></thead>
                        <tbody>
                            <tr v-if="!shown.length"><td colspan="8" class="text-center py-5 text-muted">{{ filter === 'ignored' ? 'Nothing ignored yet. Signals the engine did not act on because the stock is already held, or an earlier signal for it is still waiting, are kept here.' : (signals.length ? 'No signals in this group.' : 'No alerts yet. When ChartInk calls the webhook they appear here within a second.') }}</td></tr>
                            <template v-for="s in shown" :key="s.id">
                                <tr>
                                    <td class="p-0 ps-2"><button v-if="s.checks" class="tbl-icon-btn" :title="timeline[s.id] ? 'Hide how its status changed' : 'Show how its status changed over time'" @click="toggleChecks(s)"><i class="bi" :class="timeline[s.id] ? 'bi-chevron-down' : 'bi-chevron-right'"></i></button></td>
                                    <td class="text-nowrap mono">{{ ts(s.received_at) }}<div class="cell-secondary" style="margin:0" title="The time ChartInk gave the alert">fired {{ s.triggered_at.slice(11, 16) }}</div></td>
                                    <td class="cell-primary mono">{{ s.symbol }}<span class="ls-ex" :class="{ 'ls-ex-bse': s.exchange === 'BSE' }" :title="s.exchange === 'BSE' ? 'Not listed on NSE: traded on BSE' : 'Traded on NSE'">{{ s.exchange }}</span><button class="ls-chart-btn" title="View the chart of the day" @click="openChart(s.symbol, s.triggered_at)"><i class="bi bi-graph-up"></i></button>
                                        <div v-if="s.sector || s.market_cap" class="cell-secondary" style="margin:0">{{ [s.sector, s.market_cap].filter(Boolean).join(' · ') }}</div></td>
                                    <td class="text-muted">{{ s.alert_name }}<div v-if="s.scan_name" class="cell-secondary" style="margin:0" title="The ChartInk scan this alert came from">{{ s.scan_name }}</div></td>
                                    <td class="text-num">{{ s.trigger_price ? fmtPrice(s.trigger_price) : '—' }}<div v-if="s.alert_rsi !== null" class="cell-secondary" style="margin:0">RSI {{ fmtNum(s.alert_rsi, 1) }}</div></td>
                                    <td>
                                        <template v-if="s.verdict"><span class="status-chip" :class="VERDICT[s.verdict][0]" :title="s.verdict === 'skip' ? 'The daily / monthly picture is weak right now — the signal is still watched all day' : ''">{{ VERDICT[s.verdict][1] }}</span><span v-if="s.score !== null" class="cell-secondary ms-1" style="margin:0" title="How many of the checks passed">{{ s.score }}%</span></template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td><span class="status-chip" :class="SIGNAL_CHIP[s.status]">{{ SIGNAL_LABEL[s.status] || s.status }}</span></td>
                                    <td class="ls-reason">{{ s.reason || '' }}</td>
                                </tr>
                                <tr v-if="timeline[s.id]" class="ls-timeline-row">
                                    <td></td>
                                    <td colspan="7">
                                        <div class="ls-timeline-title">Status over time — each time the engine looked at {{ s.symbol }}</div>
                                        <table class="table table-sm mb-0 ls-timeline">
                                            <thead><tr><th>Looked at</th><th>Candle</th><th>Rated</th><th class="text-end">Candle close</th><th class="text-end">Live price</th><th class="text-end">RSI</th><th class="text-end">vs VWAP</th><th class="text-end">Day move</th><th>What failed</th></tr></thead>
                                            <tbody>
                                                <tr v-for="c in timeline[s.id]" :key="c.id">
                                                    <td class="mono text-nowrap">{{ c.checked_at.slice(11, 19) }}<span v-if="c.kind === 'recheck'" class="status-chip chip-neutral ms-1" title="Checked again just before a buy">re-check</span></td>
                                                    <td class="mono">{{ c.candle || '—' }}</td>
                                                    <td><span class="status-chip" :class="VERDICT[c.verdict][0]">{{ VERDICT[c.verdict][1] }}</span></td>
                                                    <td class="text-num">{{ c.price ? fmtPrice(c.price) : '—' }}</td>
                                                    <td class="text-num">{{ c.ltp ? fmtPrice(c.ltp) : '—' }}</td>
                                                    <td class="text-num">{{ c.rsi !== null ? c.rsi : '—' }}</td>
                                                    <td class="text-num">{{ c.vwap_dist !== null ? pct(c.vwap_dist) : '—' }}</td>
                                                    <td class="text-num">{{ c.day_chg !== null ? pct(c.day_chg) : '—' }}</td>
                                                    <td class="ls-reason">{{ c.verdict === 'enter' ? 'all checks pass' : (c.note || failText(c)) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    </div>
                </div>

                <!-- ── positions ── -->
                <div v-else-if="tab === 'positions'" class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Stock</th><th>Status</th><th class="text-end">Qty</th><th class="text-end">Avg price</th><th class="text-end">Last price</th><th class="text-end">Unrealised</th><th class="text-end">Averages</th><th class="text-end">Target</th><th>Bought</th><th class="text-end">Exit</th></tr></thead>
                        <tbody>
                            <tr v-if="!positions.open.length"><td colspan="10" class="text-center py-4 text-muted">Nothing held right now.</td></tr>
                            <tr v-for="p in positions.open" :key="p.id">
                                <td class="cell-primary mono">{{ p.symbol }}<span class="ls-ex" :class="{ 'ls-ex-bse': p.exchange === 'BSE' }">{{ p.exchange }}</span><button class="ls-chart-btn" title="View the chart of the day" @click="openChart(p.symbol, '')"><i class="bi bi-graph-up"></i></button><span v-if="p.mode === 'dry_run'" class="status-chip chip-neutral ms-2" title="Simulated: no real order">dry run</span></td>
                                <td><span class="status-chip" :class="POSITION_CHIP[p.status]">{{ p.status === 'pending' ? 'waiting to fill' : p.status }}</span><span v-if="p.exit_requested_at" class="status-chip chip-amber ms-1" title="You asked to close it: the sell is being placed / retried every minute">exit requested</span></td>
                                <td class="text-num">{{ p.qty ? fmtNum(p.qty) : '—' }}</td>
                                <td class="text-num">{{ p.qty ? fmtPrice(p.avg_price) : '—' }}</td>
                                <td class="text-num">{{ p.last_price ? fmtPrice(p.last_price) : '—' }}</td>
                                <td class="text-num"><template v-if="p.unrealised !== null"><span :class="pnlClass(p.unrealised)">{{ money(p.unrealised) }}</span><div class="cell-secondary" :class="pnlClass(p.unrealised_pct)" style="margin:0">{{ pct(p.unrealised_pct) }}</div></template><span v-else class="text-muted">—</span></td>
                                <td class="text-num">{{ p.adds }}</td>
                                <td class="text-num"><template v-if="p.target_price"><span class="pnl-pos">{{ fmtPrice(p.target_price) }}</span></template><span v-else class="text-muted">—</span>
                                    <div v-if="p.stop_price || p.hold_until" class="cell-secondary" style="margin:0"><template v-if="p.stop_price">stop {{ fmtPrice(p.stop_price) }}</template><template v-if="p.stop_price && p.hold_until"> · </template><template v-if="p.hold_until">sell {{ fmtDate(p.hold_until) }}</template></div></td>
                                <td class="text-nowrap mono">{{ ts(p.opened_at || p.created_at) }}</td>
                                <td class="text-end"><button class="btn-secondary-sm ls-exit-btn" :disabled="exiting === p.id" :title="p.status === 'pending' ? 'Cancel the buy order' : 'Sell everything now'" @click="exitPosition(p)">
                                    <span v-if="exiting === p.id" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-box-arrow-right"></i> {{ p.status === 'pending' ? 'Cancel buy' : 'Exit' }}</button></td>
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
