<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtDate, pnlClass, todayString } from '@/Service/Utils/Format.js';
import StrategyPanel from '@/Includes/Scanner/StrategyPanel.vue';
import EquityChart from '@/Includes/Scanner/EquityChart.vue';
import TradeTable from '@/Includes/Scanner/TradeTable.vue';
import QualityTab from '@/Includes/Scanner/QualityTab.vue';
import DayChart from '@/Includes/LiveSignals/DayChart.vue';

// Scanner signals — BACKTESTING only. The scanner's historical signals over a period you pick, replayed on 5-minute candles under
// rules you choose. Nothing here trades; live trading is a separate module (Live Signals / Live Config).
const overview = ref(null);
const params   = ref(null);
const qualityParams = ref(null);   // the thresholds of the Signal quality tab (shared: the backtest uses them when its signal filter is on)

// the chart of one backtest trade's day, with its signal, buys and sell drawn on the candles the backtest used
const tradeChart = ref({ show: false, symbol: '', date: '', marks: null });
function openTradeChart(t) {
    const p = params.value || {};
    const fills = t.tranches.map((tr, i) => ({ date: tr.at.slice(0, 10), t: tr.at.slice(11, 16), price: tr.price, qty: tr.qty, side: 'buy', kind: i === 0 ? 'entry' : 'average' }));
    if (t.exit_reason !== 'open') fills.push({ date: t.exit_at.slice(0, 10), t: t.exit_at.slice(11, 16), price: t.exit_price, qty: t.qty, side: 'sell', kind: t.exit_reason });
    const lines = [{ label: 'average', price: t.avg_price, color: '#2E6BFF' }];
    const manual = p.strategy === 'manual';
    const stopPct = manual ? p.m_stop_pct : p.stop_pct, targetPct = manual ? p.m_target_pct : p.target_pct;
    if (stopPct)   lines.push({ label: 'stop ' + stopPct + '%',     price: +(t.avg_price * (1 - stopPct / 100)).toFixed(2),   color: '#E03131' });
    if (targetPct) lines.push({ label: (manual ? 'day-2 exit ' : 'target ') + targetPct + '%', price: +(t.avg_price * (1 + targetPct / 100)).toFixed(2), color: '#0E9F5B' });
    tradeChart.value = {
        show: true, symbol: t.symbol, date: t.tranches[0].at.slice(0, 10),
        marks: { alerts: [{ date: t.signal_at.slice(0, 10), t: t.signal_at.slice(11, 16), price: null, alert: 'Scanner', status: 'done' }], fills, lines },
    };
}
const tab      = ref('results');     // results | compare | insights
const result   = ref(null);
const compare  = ref(null);
const insights = ref(null);
const busy     = ref({ run: false, compare: false, insights: false });
const ranWith  = ref({ results: '', compare: '', insights: '' });   // the rules + period each tab was last computed with
const banner   = ref(null);

// the period: the last month by default
const monthAgo = () => { const d = new Date(); d.setMonth(d.getMonth() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const to   = ref(todayString());
const from = ref(monthAgo());

let poll = null;

const avgHeld = computed(() => result.value && result.value.trades.length ? result.value.trades.reduce((s, t) => s + t.held_days, 0) / result.value.trades.length : 0);
const money = (v) => v === null || v === undefined ? '—' : (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);
const pct   = (v, d = 2) => v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + fmtNum(v, d) + '%';

// ─── rules → what the server wants ───────────────────────────────────

function payload() {
    const p = JSON.parse(JSON.stringify(params.value));
    p.min_turnover = (Number(p.min_turnover_cr) || 0) * 10000000;
    delete p.min_turnover_cr;
    ['stop_pct', 'target_pct', 'min_day_change', 'max_day_change'].forEach(k => { if (p[k] === '' || p[k] === undefined) p[k] = null; });
    return p;
}
const key  = () => JSON.stringify([from.value, to.value, payload(), params.value && params.value.quality_mode !== 'off' ? qualityParams.value : null]);
const body = () => ({ params: payload(), from: from.value, to: to.value, quality_params: qualityParams.value || {} });

const failText = (data, fallback) => typeof data.message === 'string' ? data.message : fallback;

// ─── loading ─────────────────────────────────────────────────────────

function loadOverview() {
    return DBService.postData('/api/scanner/overview', { from: from.value, to: to.value }).then((data) => {
        if (!data.success) { banner.value = { bad: true, message: failText(data, 'Could not load') }; return; }
        overview.value = data;
        if (!params.value) {
            const d = JSON.parse(JSON.stringify(data.defaults));
            d.min_turnover_cr = d.min_turnover / 10000000;
            d.quality_mode = 'off';
            ['stop_pct', 'target_pct', 'min_day_change', 'max_day_change'].forEach(k => { if (d[k] === null || d[k] === undefined) d[k] = ''; });
            params.value = d;
        }
        if (data.fetch.running) startPolling();
        return data;
    });
}

const ready = computed(() => overview.value && overview.value.signals.count > 0 && overview.value.candles.symbols_5min > 0);

onMounted(() => loadOverview().then(() => { if (ready.value) run(); }));
onBeforeUnmount(() => clearInterval(poll));

// changing the period refreshes the counts; the results stay until Run is pressed
function periodChanged() {
    if (from.value && to.value && from.value <= to.value) loadOverview();
}

function run() {
    if (busy.value.run) return;
    busy.value.run = true;
    tab.value = 'results';      // pressing Run shows the result; a run that finishes later must not pull you off another tab
    const k = key();
    DBService.postData('/api/scanner/run', body()).then((data) => {
        busy.value.run = false;
        if (!data.success) { result.value = null; banner.value = { bad: true, message: failText(data, 'The backtest failed — try a shorter period') }; return; }
        banner.value = null;
        result.value = data;
        ranWith.value.results = k;
    });
}

function loadCompare() {
    busy.value.compare = true;
    const k = key();
    DBService.postData('/api/scanner/compare', body()).then((data) => {
        busy.value.compare = false;
        if (!data.success) { banner.value = { bad: true, message: failText(data, 'The comparison failed — try a shorter period') }; return; }
        compare.value = data.rows;
        ranWith.value.compare = k;
    });
}

function loadInsights() {
    busy.value.insights = true;
    const k = key();
    DBService.postData('/api/scanner/behaviour', body()).then((data) => {
        busy.value.insights = false;
        if (!data.success) { banner.value = { bad: true, message: failText(data, 'The measurement failed — try a shorter period') }; return; }
        insights.value = data;
        ranWith.value.insights = k;
    });
}

function openTab(t) {
    tab.value = t;
    if (!ready.value) return;
    if (t === 'compare'  && ranWith.value.compare  !== key() && !busy.value.compare)  loadCompare();
    if (t === 'insights' && ranWith.value.insights !== key() && !busy.value.insights) loadInsights();
}

const stale = computed(() => result.value && ranWith.value.results !== key());

// ─── candles from Kite, for the period ───────────────────────────────

function fetchCandles() {
    banner.value = null;
    DBService.postData('/api/scanner/fetch', { from: from.value, to: to.value }).then((data) => {
        if (!data.success) { banner.value = { bad: true, message: failText(data, 'Could not start the download') }; return; }
        startPolling();
        loadOverview();
    });
}

function startPolling() {
    clearInterval(poll);
    poll = setInterval(() => {
        DBService.postData('/api/scanner/fetch-status', {}).then((s) => {
            if (!s.success) return;
            overview.value = { ...overview.value, fetch: s };
            if (!s.running) {
                clearInterval(poll);
                loadOverview().then(() => {
                    const failed = (s.failed || []).length;
                    banner.value = s.error ? { bad: true, message: s.error }
                        : { ok: !failed, bad: false, message: `Candles downloaded for ${s.done} stocks` + (failed ? ` — ${failed} could not be fetched (${s.failed.slice(0, 4).map(f => f.symbol).join(', ')}${failed > 4 ? '…' : ''})` : '') };
                    if (ready.value) run();
                });
            }
        });
    }, 2000);
}

const fetchPct = computed(() => {
    const f = overview.value && overview.value.fetch;
    return f && f.total ? Math.round(f.done / f.total * 100) : 0;
});

const skipList = computed(() => result.value ? Object.entries(result.value.skips).filter(([k]) => k !== 'entered') : []);
</script>

<template>
    <PageHeader
        title="Old Signals"
        subtitle="Old Chartink signals replayed on 5-minute candles — daily profit and loss under rules you choose (backtest only)"
        :show-search="false"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="overview && overview.fetch.running" @click="fetchCandles" title="Download the 5-minute and daily candles the signals of this period need from Kite (only what is missing)">
                <span v-if="overview && overview.fetch.running" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-cloud-download"></i>
                Fetch candles for this period
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="banner" class="pf-alert zt-result" :class="{ 'pf-alert-ok': banner.ok, 'pf-alert-bad': banner.bad }">
            <i class="bi" :class="banner.bad ? 'bi-x-circle-fill' : (banner.ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill')"></i>
            <div class="flex-grow-1">{{ banner.message }}</div>
            <button class="tbl-icon-btn" @click="banner = null"><i class="bi bi-x-lg"></i></button>
        </div>

        <div v-if="!overview" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</div>

        <template v-else>
            <!-- what is loaded -->
            <div class="pf-summary">
                <div class="pf-stat">
                    <div class="pf-stat-label">Signals in this period</div>
                    <div class="pf-stat-value">{{ fmtNum(overview.signals.count) }}</div>
                    <div class="pf-stat-sub">{{ overview.signals.symbols }} stocks on {{ overview.signals.days }} days</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">All signals on file</div>
                    <div class="pf-stat-value" style="font-size:15px">{{ fmtNum(overview.available.count) }}</div>
                    <div class="pf-stat-sub"><template v-if="overview.available.first_date">{{ fmtDate(overview.available.first_date) }} – {{ fmtDate(overview.available.last_date) }}</template><template v-else>none yet</template></div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-label">Candles from Kite</div>
                    <div class="pf-stat-value">{{ overview.candles.symbols_5min }} <span class="text-muted" style="font-size:14px">/ {{ overview.signals.symbols }}</span></div>
                    <div class="pf-stat-sub">stocks of this period with 5-minute data</div>
                </div>
                <div v-if="overview.fetch.running" class="pf-stat">
                    <div class="pf-stat-label">Downloading</div>
                    <div class="pf-stat-value">{{ fetchPct }}%</div>
                    <div class="pf-stat-sub">{{ overview.fetch.done }} / {{ overview.fetch.total }} · {{ overview.fetch.current }}</div>
                </div>
            </div>

            <!-- the period to test -->
            <div class="sc-set">
                <span class="cell-secondary" style="margin:0">Test signals from</span>
                <input v-model="from" type="date" class="form-control form-control-sm" style="width:auto" @change="periodChanged" />
                <span class="cell-secondary" style="margin:0">to</span>
                <input v-model="to" type="date" class="form-control form-control-sm" style="width:auto" @change="periodChanged" />
                <span class="cell-secondary" style="margin:0">Only these days' signals and candles are read, which keeps it quick.</span>
            </div>

            <div v-if="overview.signals.count === 0" class="card-panel p-4 text-center text-muted">
                No scanner signals between these dates.<template v-if="overview.available.first_date"> Signals on file run from {{ fmtDate(overview.available.first_date) }} to {{ fmtDate(overview.available.last_date) }}.</template>
            </div>
            <div v-else-if="overview.candles.symbols_5min === 0 && !overview.fetch.running" class="card-panel p-4 text-center text-muted">
                No candles for this period yet. Use <strong>Fetch candles for this period</strong> to download what the signals are replayed on.
            </div>

            <template v-if="params && overview.signals.count > 0">
                <StrategyPanel v-if="tab !== 'quality'" v-model:params="params" :presets="overview.presets" :busy="busy.run" @run="run" />

                <div class="card-panel mt-3">
                    <div class="pf-tabs">
                        <button class="pf-tab" :class="{ active: tab === 'results' }" @click="openTab('results')">Daily P&amp;L</button>
                        <button class="pf-tab" :class="{ active: tab === 'compare' }" @click="openTab('compare')">Compare strategies</button>
                        <button class="pf-tab" :class="{ active: tab === 'insights' }" @click="openTab('insights')">What happens next</button>
                        <button class="pf-tab" :class="{ active: tab === 'quality' }" @click="openTab('quality')">Signal quality</button>
                    </div>

                    <!-- ── daily P&L ── -->
                    <div v-if="tab === 'results'">
                        <div v-if="!ready" class="text-center text-muted py-5">Fetch the candles first.</div>
                        <div v-else-if="!result" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Running…</div>
                        <template v-else>
                            <div v-if="result.leak && result.leak.suspicious" class="sc-leak">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong>These profits are probably overstated: every flagged day went on to rally.</strong>
                                    When a stock was first flagged it had moved a median {{ pct(result.leak.at_signal.median) }} on the day, yet
                                    {{ fmtNum(result.leak.eod_ge_3, 0) }}% of the {{ result.leak.stock_days }} flagged stock-days finished at least +3% (lowest {{ pct(result.leak.eod.min) }}, median {{ pct(result.leak.eod.median) }}).
                                    This pattern is typical when a rule on <em>daily</em> candles (for example a daily Bollinger band) is back-tested using the day's final values: the stock is stamped “9:15” on a day it later broke out.
                                    The app cannot see your scanner's rule, so it can only report this pattern. To confirm, compare against alerts recorded <em>live</em> (see “What happens next”).
                                </div>
                            </div>
                            <div v-if="stale" class="sc-stale"><i class="bi bi-exclamation-circle"></i> The rules changed since this was run — press <strong>Run</strong>.</div>

                            <div v-if="result.quality" class="sc-stale" style="background:#EEF4FF;color:#2447A8"><i class="bi bi-funnel"></i>
                                Signal quality filter: of {{ fmtNum(result.quality.signals) }} signals, {{ fmtNum(result.quality.enter) }} were "Enter now"<template v-if="result.quality.mode === 'wait'">, {{ fmtNum(result.quality.waited) }} more were entered later when the chart was right</template>; {{ fmtNum(result.quality.dropped) }} were left out. Thresholds are on the <a href="#" @click.prevent="openTab('quality')">Signal quality</a> tab.
                            </div>
                            <div class="sc-kpis">
                                <div class="sc-kpi"><span>Net P&amp;L</span><strong :class="pnlClass(result.summary.net)">{{ money(result.summary.net) }}</strong><small>gross {{ money(result.summary.gross) }} · charges ₹{{ fmtNum(result.summary.charges, 0) }}</small></div>
                                <div class="sc-kpi"><span>Trades</span><strong>{{ result.summary.trades }}</strong><small>{{ result.summary.wins }} won · {{ result.summary.losses }} lost ({{ fmtNum(result.summary.win_rate, 0) }}%)</small></div>
                                <div class="sc-kpi"><span>Return on money used</span><strong :class="pnlClass(result.summary.return_pct)">{{ pct(result.summary.return_pct) }}</strong><small>avg {{ money(result.summary.avg_net) }} a trade · {{ pct(result.summary.avg_pct) }}</small></div>
                                <div class="sc-kpi"><span>Days held</span><strong>{{ fmtNum(avgHeld, 1) }} <span class="text-muted" style="font-size:14px">avg</span></strong><small>best trade {{ money(result.summary.best) }} · worst {{ money(result.summary.worst) }}</small></div>
                                <div class="sc-kpi"><span>Max drawdown</span><strong class="pnl-neg">₹{{ fmtNum(result.summary.max_drawdown, 0) }}</strong><small>peak to trough of the running total</small></div>
                                <div class="sc-kpi"><span>Most money out at once</span><strong>₹{{ fmtNum(result.summary.peak_capital, 0) }}</strong><small>profit factor {{ result.summary.profit_factor ?? '—' }}</small></div>
                            </div>

                            <div v-if="result.summary.open_positions" class="sc-stale"><i class="bi bi-info-circle"></i> {{ result.summary.open_positions }} position{{ result.summary.open_positions === 1 ? ' is' : 's are' }} still open at the end of the data — valued at the last candle.</div>

                            <div class="px-3"><EquityChart :days="result.days" /></div>

                            <div class="sc-skips">
                                <span class="cell-secondary" style="margin:0">Stock-days: <strong>{{ result.skips.entered || 0 }}</strong> traded</span>
                                <span v-for="[k, v] in skipList" :key="k" class="status-chip chip-neutral" :title="'Signals not traded: ' + k">{{ k }} · {{ v }}</span>
                            </div>

                            <TradeTable :trades="result.trades" @chart="openTradeChart" />

                            <div class="sc-breakdown">
                                <div>
                                    <div class="sc-sub">By market cap</div>
                                    <table class="trade-actions"><thead><tr><th></th><th class="text-end">Trades</th><th class="text-end">Win %</th><th class="text-end">Net</th><th class="text-end">Return</th></tr></thead>
                                        <tbody><tr v-for="g in result.by_cap" :key="g.key"><td>{{ g.key }}</td><td class="text-num">{{ g.trades }}</td><td class="text-num">{{ fmtNum(g.win_rate, 0) }}%</td><td class="text-num" :class="pnlClass(g.net)">{{ money(g.net) }}</td><td class="text-num" :class="pnlClass(g.avg_pct)">{{ pct(g.avg_pct) }}</td></tr></tbody></table>
                                </div>
                                <div>
                                    <div class="sc-sub">By when the signal came</div>
                                    <table class="trade-actions"><thead><tr><th></th><th class="text-end">Trades</th><th class="text-end">Win %</th><th class="text-end">Net</th><th class="text-end">Return</th></tr></thead>
                                        <tbody><tr v-for="g in [...result.by_time].sort((a, b) => a.key.localeCompare(b.key))" :key="g.key"><td>{{ g.key.slice(3) }}</td><td class="text-num">{{ g.trades }}</td><td class="text-num">{{ fmtNum(g.win_rate, 0) }}%</td><td class="text-num" :class="pnlClass(g.net)">{{ money(g.net) }}</td><td class="text-num" :class="pnlClass(g.avg_pct)">{{ pct(g.avg_pct) }}</td></tr></tbody></table>
                                </div>
                            </div>
                            <p class="sc-note">A backtest, not a promise: fills are assumed at candle prices plus the slippage above, charges are an estimate of Zerodha's, and each day is the day the shares were sold. Thin stocks can be hard to buy at the printed price, especially near a circuit limit.</p>
                        </template>
                    </div>

                    <!-- ── compare ── -->
                    <div v-else-if="tab === 'compare'">
                        <div v-if="!ready" class="text-center text-muted py-5">Fetch the candles first.</div>
                        <div v-else-if="busy.compare || !compare" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Running every strategy…</div>
                        <template v-else>
                            <div v-if="ranWith.compare !== key()" class="sc-stale"><i class="bi bi-exclamation-circle"></i> The filters changed — <a href="#" @click.prevent="loadCompare">re-run the comparison</a>.</div>
                            <p class="sc-note" style="margin-top:12px">Each row uses its own buy / sell rules; the filters, slippage and time window come from the rules panel above.</p>
                            <div class="px-3"><EquityChart :lines="compare.map(r => ({ name: r.name, days: r.days }))" :height="300" /></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead><tr><th>Strategy</th><th class="text-end">Trades</th><th class="text-end">Win %</th><th class="text-end">Net P&amp;L</th><th class="text-end" title="Net ÷ money put in">Return</th><th class="text-end">Max drawdown</th><th class="text-end">Most out at once</th><th class="text-end">Profit factor</th></tr></thead>
                                    <tbody>
                                        <tr v-for="r in [...compare].sort((a, b) => b.summary.net - a.summary.net)" :key="r.id">
                                            <td style="white-space:normal;min-width:260px">{{ r.name }}</td>
                                            <td class="text-num">{{ r.summary.trades }}</td>
                                            <td class="text-num">{{ fmtNum(r.summary.win_rate, 0) }}%</td>
                                            <td class="text-num fw-semibold" :class="pnlClass(r.summary.net)">{{ money(r.summary.net) }}</td>
                                            <td class="text-num" :class="pnlClass(r.summary.return_pct)">{{ pct(r.summary.return_pct) }}</td>
                                            <td class="text-num pnl-neg">₹{{ fmtNum(r.summary.max_drawdown, 0) }}</td>
                                            <td class="text-num">₹{{ fmtNum(r.summary.peak_capital, 0) }}</td>
                                            <td class="text-num">{{ r.summary.profit_factor ?? '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>

                    <!-- ── signal quality: how good each signal is as an entry (no profit and loss) ── -->
                    <QualityTab v-else-if="tab === 'quality'" v-model:params="qualityParams" :from="from" :to="to" />

                    <!-- ── what happens next ── -->
                    <div v-else-if="tab === 'insights'">
                        <div v-if="!ready" class="text-center text-muted py-5">Fetch the candles first.</div>
                        <div v-else-if="busy.insights || !insights" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Measuring…</div>
                        <template v-else>
                            <div v-if="result && result.leak && result.leak.enough" class="sc-leak-card" :class="{ bad: result.leak.suspicious }">
                                <div class="sc-sub" style="margin-bottom:8px">Look-ahead check <span v-if="result.leak.suspicious" class="status-chip chip-red ms-1">fails</span><span v-else class="status-chip chip-green ms-1">passes</span></div>
                                <table class="trade-actions">
                                    <thead><tr><th></th><th class="text-end">Lowest</th><th class="text-end">Median</th><th class="text-end">Highest</th></tr></thead>
                                    <tbody>
                                        <tr><td>Move on the day when first flagged</td><td class="text-num">{{ pct(result.leak.at_signal.min) }}</td><td class="text-num">{{ pct(result.leak.at_signal.median) }}</td><td class="text-num">{{ pct(result.leak.at_signal.max) }}</td></tr>
                                        <tr><td>Where the same day finished</td><td class="text-num">{{ pct(result.leak.eod.min) }}</td><td class="text-num">{{ pct(result.leak.eod.median) }}</td><td class="text-num">{{ pct(result.leak.eod.max) }}</td></tr>
                                    </tbody>
                                </table>
                                <p class="mb-0 mt-2" style="font-size:12.5px">
                                    {{ result.leak.stock_days }} stock-days: {{ fmtNum(result.leak.eod_ge_3, 0) }}% finished ≥ +3%, {{ fmtNum(result.leak.eod_ge_5, 0) }}% ≥ +5%;
                                    {{ fmtNum(result.leak.at_signal_lt_3_but_eod_ge_5, 0) }}% were flagged while under +3% and still finished ≥ +5%.
                                    A scanner that sees only the past would show many flagged days that fade. When almost none do, the stamps probably use the day's final values.
                                    <strong>What to do:</strong> trust only signals that were recorded live, at the time they actually fired (the Live Trading webhook now stores these), and this check and the P&amp;L become meaningful.
                                </p>
                            </div>
                            <p class="sc-note" style="margin-top:12px">
                                After the <strong>first</strong> signal of each stock each day (filters as above), measured from the entry price, no position rules. “Up / down” is how the stock finished that day.
                                The last two columns test “if it falls, it picks up again next day”: the next day's close against <em>that day's own close</em>.
                            </p>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead><tr>
                                        <th></th><th class="text-end">Stock-days</th>
                                        <th class="text-end" title="Entry → that day's close">To the close</th><th class="text-end">Up at close</th>
                                        <th class="text-end" title="Average best point reached after entry that day">Best point</th><th class="text-end" title="Average worst point reached after entry that day">Worst point</th>
                                        <th class="text-end" title="Entry → next day's open">Next open</th><th class="text-end" title="Entry → next day's close">Next close</th><th class="text-end">Up at next close</th>
                                        <th class="text-end" title="Next day's close against the day's own close">Next close vs its day close</th><th class="text-end">…how often up</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="g in insights.groups" :key="g.label">
                                            <td class="fw-semibold">{{ g.label }}</td>
                                            <td class="text-num">{{ g.events }}<div class="cell-secondary" style="margin:0">{{ g.with_next_day }} with a next day</div></td>
                                            <td class="text-num" :class="pnlClass(g.eod_avg)">{{ pct(g.eod_avg) }}</td>
                                            <td class="text-num">{{ g.eod_win === null ? '—' : fmtNum(g.eod_win, 0) + '%' }}</td>
                                            <td class="text-num pnl-pos">{{ pct(g.best_avg) }}</td>
                                            <td class="text-num pnl-neg">{{ pct(g.worst_avg) }}</td>
                                            <td class="text-num" :class="pnlClass(g.next_open_avg)">{{ pct(g.next_open_avg) }}</td>
                                            <td class="text-num" :class="pnlClass(g.next_close_avg)">{{ pct(g.next_close_avg) }}</td>
                                            <td class="text-num">{{ g.next_close_win === null ? '—' : fmtNum(g.next_close_win, 0) + '%' }}</td>
                                            <td class="text-num" :class="pnlClass(g.next_vs_eod_avg)">{{ pct(g.next_vs_eod_avg) }}</td>
                                            <td class="text-num">{{ g.next_vs_eod_win === null ? '—' : fmtNum(g.next_vs_eod_win, 0) + '%' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </template>

        <DayChart :show="tradeChart.show" :symbol="tradeChart.symbol" :date="tradeChart.date" :marks="tradeChart.marks" endpoint="/api/scanner/chart" @close="tradeChart.show = false" />
    </div>
</template>
