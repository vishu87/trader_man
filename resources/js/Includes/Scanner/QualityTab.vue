<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import DayChart from '@/Includes/LiveSignals/DayChart.vue';
import { fmtNum, fmtPrice, fmtDate } from '@/Service/Utils/Format.js';

// Signal QUALITY — no profit and loss, no exit. How good was each signal as an entry, judged from the 5-minute chart at the signal,
// the daily chart before it and the monthly picture. A "Watch" signal is a good stock at a bad moment: the next candle at which every
// timing check passes is shown as the entry that was waited for. Read it, open the chart, and judge it yourself.
const props = defineProps({
    from: { type: String, required: true },
    to:   { type: String, required: true },
});

const data    = ref(null);
const loading = ref(false);
const error   = ref('');
const params  = defineModel('params', { default: null });   // the thresholds being edited (kept by the page: the backtest's signal filter uses them too)
const showRules = ref(false);
const verdict = ref('all');         // all | enter | watch | skip
const search  = ref('');
const page    = ref(0);
const PAGE    = 100;
const open    = ref({});
const chart   = ref({ show: false, symbol: '', date: '', marks: null });
let seq = 0;

function load() {
    const mine = ++seq;
    loading.value = true;
    error.value = '';
    DBService.postData('/api/scanner/quality', { from: props.from, to: props.to, params: params.value || {} }).then((d) => {
        if (mine !== seq) return;
        loading.value = false;
        if (!d.success) { error.value = typeof d.message === 'string' ? d.message : 'Could not load'; data.value = null; return; }
        data.value = d;
        if (!params.value) params.value = JSON.parse(JSON.stringify(d.params));
        page.value = 0; open.value = {};
    }).catch(() => { if (mine === seq) { loading.value = false; error.value = 'Could not load'; } });
}
onMounted(load);
watch(() => [props.from, props.to], load);
watch([verdict, search], () => { page.value = 0; });

const labels = computed(() => Object.fromEntries((data.value ? data.value.checks : []).map(c => [c.code, c.label])));
const shorts = computed(() => Object.fromEntries((data.value ? data.value.checks : []).map(c => [c.code, c.short])));

const rows = computed(() => {
    if (!data.value) return [];
    const q = search.value.trim().toUpperCase();
    return data.value.rows.filter(r => (verdict.value === 'all' || r.verdict === verdict.value) && (!q || r.symbol.includes(q)));
});
const pageRows = computed(() => rows.value.slice(page.value * PAGE, (page.value + 1) * PAGE));
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / PAGE)));

const VERDICT = { enter: ['chip-green', 'Enter now'], watch: ['chip-amber', 'Watch'], skip: ['chip-red', 'Skip'], nodata: ['chip-neutral', 'No data'] };
const n = (v, d = 1, suffix = '') => v === null || v === undefined ? '—' : fmtNum(v, d) + suffix;
const sgn = (v, d = 1) => v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + fmtNum(v, d) + '%';
const tone = (v) => v === null || v === undefined ? '' : (v > 0 ? 'pnl-pos' : v < 0 ? 'pnl-neg' : '');

// what each check looked at, for the opened row
function valueOf(code, r) {
    const f = r.f, c = f ? f.ctx : null;
    if (!f) return '';
    return ({
        green:    `open → close ${sgn(f.body_pct, 2)}`,
        day_chg:  `${sgn(f.day_chg, 2)} on the previous close`,
        from_low: `${sgn(f.from_low, 2)} above the day's low`,
        vwap:     `${sgn(f.vwap_dist, 2)} vs VWAP`,
        rsi_hi:   `RSI ${n(f.rsi)}`,
        rsi_lo:   `RSI ${n(f.rsi)}`,
        volume:   `${n(f.vol_ratio, 2)}× the last 20 candles`,
        window:   `candle ${f.at}`,
        d_trend:  `${sgn(c.d_vs_sma20, 2)} vs daily 20 SMA · ${(c.d_green || []).slice(-params.value.d_green_days).filter(Boolean).length} of the last ${Math.min(params.value.d_green_days, (c.d_green || []).length)} daily candles green`,
        d_chg5:   `${sgn(c.d_chg5, 2)} in 5 days`,
        d_rsi:    `daily RSI ${n(c.d_rsi)}`,
        m_chg1:   `${sgn(c.m_chg1, 2)} last month`,
    })[code];
}
const status = (code, r) => r.na.includes(code) ? 'na' : r.fails.includes(code) ? 'fail' : 'pass';

function openChart(r) {
    const alerts = [{ date: r.signal_at.slice(0, 10), t: r.signal_at.slice(11, 16), price: r.f ? r.f.price : null, alert: 'Scanner signal', status: r.verdict === 'enter' ? 'done' : 'watch', reason: r.fails.map(c => labels.value[c]).join(' · ') || r.verdict }];
    const fills = r.found ? [{ date: r.signal_at.slice(0, 10), t: r.found.t, price: r.found.price, qty: null, side: 'buy', kind: 'entry waited for' }] : [];
    chart.value = { show: true, symbol: r.symbol, date: r.signal_at.slice(0, 10), marks: { alerts, fills, lines: [] } };
}
</script>

<template>
    <div class="q-wrap">
        <div v-if="error" class="pf-alert pf-alert-bad zt-result m-3"><i class="bi bi-x-circle-fill"></i><div class="flex-grow-1">{{ error }}</div></div>

        <!-- thresholds -->
        <div class="q-rules">
            <button class="btn-secondary-sm" @click="showRules = !showRules"><i class="bi" :class="showRules ? 'bi-chevron-down' : 'bi-sliders'"></i> What counts as a good entry</button>
            <span class="cell-secondary" style="margin:0">Timing checks (the 5-minute candle) fail → <strong>Watch</strong>. Context checks (daily / monthly) fail → <strong>Skip</strong>. Nothing fails → <strong>Enter now</strong>.</span>
            <span v-if="loading" class="spinner-border spinner-border-sm ms-auto"></span>
        </div>
        <div v-if="showRules && params" class="q-rules-body">
            <fieldset class="sc-group">
                <legend>Timing (5-minute candle)</legend>
                <label class="sc-field"><span>Enter from</span><input v-model="params.entry_from" type="time" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>…until</span><input v-model="params.entry_until" type="time" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Up this much on the previous close or more = already run too far today"><span>Day move ≤ %</span><input v-model.number="params.max_day_chg" type="number" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Up this much from the day's low so far or more = extended"><span>From day low ≤ %</span><input v-model.number="params.max_from_low" type="number" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Must be above VWAP, but not more than this far"><span>Above VWAP ≤ %</span><input v-model.number="params.max_vwap_dist" type="number" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>5-min RSI ≥</span><input v-model.number="params.min_rsi" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>5-min RSI ≤</span><input v-model.number="params.max_rsi" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="This candle's volume ÷ the average of the 20 before it"><span>Volume ≥ ×</span><input v-model.number="params.min_vol_ratio" type="number" step="0.1" class="form-control form-control-sm" /></label>
            </fieldset>
            <fieldset class="sc-group">
                <legend>Context (daily / monthly)</legend>
                <label class="sc-check sc-field"><input v-model="params.daily_uptrend" type="checkbox" /> Daily close above 20 SMA, with green candles</label>
                <label class="sc-field" title="A stock above its 20 SMA only because of one old spike, now falling in red candles, is not an uptrend"><span>Green daily candles ≥</span><input v-model.number="params.d_green_min" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>…of the last (days)</span><input v-model.number="params.d_green_days" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>5-day rise ≤ %</span><input v-model.number="params.max_chg_5d" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Daily RSI ≤</span><input v-model.number="params.max_daily_rsi" type="number" step="1" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Last month rise ≤ %</span><input v-model.number="params.max_chg_1m" type="number" step="5" class="form-control form-control-sm" /></label>
                <button class="btn-new-filing mt-2" :disabled="loading" @click="load"><i class="bi bi-arrow-repeat me-1"></i> Recalculate</button>
            </fieldset>
        </div>

        <!-- counts + filters -->
        <div v-if="data" class="q-bar">
            <button v-for="v in [['all', 'All', data.rows.length], ['enter', 'Enter now', data.counts.enter], ['watch', 'Watch', data.counts.watch], ['skip', 'Skip', data.counts.skip]]" :key="v[0]"
                    class="pf-tab" :class="{ active: verdict === v[0] }" style="padding:4px 10px" @click="verdict = v[0]">{{ v[1] }} <span class="pf-tab-count">{{ v[2] }}</span></button>
            <span v-if="data.counts.watch" class="cell-secondary" style="margin:0">{{ data.counts.watch_found }} of {{ data.counts.watch }} "watch" signals reached a good entry later the same day</span>
            <input v-model="search" class="form-control form-control-sm ms-auto" style="width:150px" placeholder="Stock…" />
        </div>

        <div v-if="loading && !data" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Measuring every signal…</div>

        <div v-if="data" class="table-responsive">
            <table class="table table-hover align-middle mb-0 q-table">
                <thead>
                    <tr>
                        <th style="width:26px"></th><th>Signal</th><th>Stock</th><th>Verdict</th><th class="text-end">Score</th><th>Why not</th>
                        <th class="text-end" title="5-minute RSI(14)">RSI</th><th class="text-end" title="Close vs the day's VWAP">VWAP</th><th class="text-end" title="On the previous close">Day</th>
                        <th class="text-end" title="Above the day's low">Low</th><th class="text-end" title="Candle volume ÷ average of the previous 20">Vol ×</th>
                        <th class="text-end" title="Daily RSI(14), yesterday">D RSI</th><th class="text-end" title="Yesterday's close vs daily 20 SMA">D 20SMA</th><th class="text-end" title="Last 5 daily closes">5d</th>
                        <th class="text-end" title="Last completed month">1m</th><th class="text-end" title="Last 3 completed months">3m</th><th class="text-end" title="Latest close vs the monthly upper Bollinger band (20, 2)">M BB</th>
                        <th>Entry waited for</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!pageRows.length"><td colspan="18" class="text-center py-5 text-muted">No signals here.</td></tr>
                    <template v-for="r in pageRows" :key="r.symbol + r.signal_at">
                        <tr class="q-row" @click="open[r.symbol + r.signal_at] = !open[r.symbol + r.signal_at]">
                            <td><i class="bi" :class="open[r.symbol + r.signal_at] ? 'bi-chevron-down' : 'bi-chevron-right'"></i></td>
                            <td class="text-nowrap">{{ fmtDate(r.signal_at).slice(0, 6) }}<div class="cell-secondary mono" style="margin:0">{{ r.signal_at.slice(11, 16) }}</div></td>
                            <td><span class="cell-primary mono">{{ r.symbol }}</span><button class="ls-chart-btn" title="View the chart of the day" @click.stop="openChart(r)"><i class="bi bi-graph-up"></i></button></td>
                            <td><span class="status-chip" :class="VERDICT[r.verdict][0]">{{ VERDICT[r.verdict][1] }}</span></td>
                            <td class="text-num">{{ r.score === null ? '—' : r.score }}</td>
                            <td class="q-why"><span v-for="c in r.fails" :key="c" class="q-fail" :title="labels[c]">{{ shorts[c] }}</span></td>
                            <template v-if="r.f">
                                <td class="text-num">{{ n(r.f.rsi) }}</td>
                                <td class="text-num" :class="tone(r.f.vwap_dist)">{{ sgn(r.f.vwap_dist) }}</td>
                                <td class="text-num" :class="tone(r.f.day_chg)">{{ sgn(r.f.day_chg) }}</td>
                                <td class="text-num">{{ sgn(r.f.from_low) }}</td>
                                <td class="text-num">{{ n(r.f.vol_ratio, 1) }}</td>
                                <td class="text-num">{{ n(r.f.ctx.d_rsi) }}</td>
                                <td class="text-num" :class="tone(r.f.ctx.d_vs_sma20)">{{ sgn(r.f.ctx.d_vs_sma20) }}</td>
                                <td class="text-num" :class="tone(r.f.ctx.d_chg5)">{{ sgn(r.f.ctx.d_chg5) }}</td>
                                <td class="text-num" :class="tone(r.f.ctx.m_chg1)">{{ sgn(r.f.ctx.m_chg1) }}</td>
                                <td class="text-num" :class="tone(r.f.ctx.m_chg3)">{{ sgn(r.f.ctx.m_chg3) }}</td>
                                <td class="text-num" :class="tone(r.f.ctx.m_bb)">{{ sgn(r.f.ctx.m_bb) }}</td>
                            </template>
                            <td v-else colspan="11" class="text-muted">no 5-minute candle for this signal</td>
                            <td class="text-nowrap">
                                <template v-if="r.verdict === 'enter'"><span class="text-muted">now · ₹{{ fmtPrice(r.f.price) }}</span></template>
                                <template v-else-if="r.found"><span class="mono">{{ r.found.t }}</span> · ₹{{ fmtPrice(r.found.price) }} <span :class="tone(-r.found.vs_signal_pct)" class="cell-secondary" style="margin:0">{{ sgn(r.found.vs_signal_pct, 2) }}</span></template>
                                <template v-else-if="r.verdict === 'watch'"><span class="text-muted">not that day</span></template>
                            </td>
                        </tr>
                        <tr v-if="open[r.symbol + r.signal_at] && r.f" class="q-detail">
                            <td></td>
                            <td colspan="17">
                                <div class="q-checks">
                                    <div v-for="c in data.checks" :key="c.code" class="q-check" :class="'qs-' + status(c.code, r)">
                                        <i class="bi" :class="status(c.code, r) === 'pass' ? 'bi-check-circle-fill' : status(c.code, r) === 'fail' ? 'bi-x-circle-fill' : 'bi-dash-circle'"></i>
                                        <span class="q-check-label">{{ c.label }}<small>{{ c.group === 'timing' ? 'timing' : 'context' }}</small></span>
                                        <span class="q-check-val">{{ status(c.code, r) === 'na' ? 'not enough data' : valueOf(c.code, r) }}</span>
                                    </div>
                                </div>
                                <div class="cell-secondary" style="margin:8px 0 0">
                                    Price ₹{{ fmtPrice(r.f.price) }} · gap {{ sgn(r.f.gap) }} · {{ sgn(r.f.from_open) }} from the open · {{ n(r.f.vol_yday, 0) }}% of yesterday's volume so far ·
                                    daily {{ sgn(r.f.ctx.d_vs_sma50) }} vs 50 SMA, ATR {{ n(r.f.ctx.d_atr, 1) }}%, {{ sgn(r.f.ctx.d_from_high) }} from the 52-week high · this month so far {{ sgn(r.f.ctx.m_mtd) }}
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="data && pages > 1" class="d-flex align-items-center justify-content-center gap-3 py-3">
            <button class="btn-secondary-sm" :disabled="page === 0" @click="page--"><i class="bi bi-chevron-left"></i></button>
            <span class="text-muted" style="font-size:13px">Page {{ page + 1 }} of {{ pages }} · {{ rows.length }} signals</span>
            <button class="btn-secondary-sm" :disabled="page >= pages - 1" @click="page++"><i class="bi bi-chevron-right"></i></button>
        </div>

        <DayChart :show="chart.show" :symbol="chart.symbol" :date="chart.date" :marks="chart.marks" endpoint="/api/scanner/chart" @close="chart.show = false" />
    </div>
</template>
