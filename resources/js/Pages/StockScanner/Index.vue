<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtPrice, fmtDate } from '@/Service/Utils/Format.js';
import { useCandleSync } from '@/Service/Utils/CandleSync.js';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';
import StarRating from '@/Components/Common/StarRating.vue';

// Fixed, coded scanners run over our own watchlists, on the candles already stored (nothing is fetched while scanning).
// Sync the candles first — the evening job does it by itself on weekdays.
const overview  = ref(null);
const scannerKey = ref('');
const listId    = ref('all');
const running   = ref(false);
const result    = ref(null);       // the last run: { results, scanned, no_data, no_candles, matched, ran_at, freshness }
const search    = ref('');
const ratingFilter = ref(0);       // 0 = any, 1–5 = at least that many stars, 'none' = not rated
const showRules = ref(false);

const scanner = computed(() => overview.value ? overview.value.scanners.find(s => s.key === scannerKey.value) : null);
const fresh   = computed(() => (result.value && result.value.freshness) || (overview.value && overview.value.freshness) || null);

function load() {
    return DBService.postData('/api/stock-scanner/overview', {}).then((d) => {
        if (!d.success) { alert(d.message); return; }
        overview.value = d;
        if (!scannerKey.value && d.scanners.length) scannerKey.value = d.scanners[0].key;
    });
}

const candleSync = useCandleSync(() => load());
onMounted(() => { load(); candleSync.resume(); });

function run() {
    if (!scannerKey.value || running.value) return;
    running.value = true;
    DBService.postData('/api/stock-scanner/run', { scanner: scannerKey.value, watchlist_id: listId.value }).then((d) => {
        running.value = false;
        if (!d.success) { alert(d.message); return; }
        result.value = d;
    }).catch(() => { running.value = false; });
}

// ─── Showing the results ─────────────────────────────────────────────

const sortBy  = ref('rating');
const sortDir = ref('desc');

function sort(col) {
    if (sortBy.value === col) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    else { sortBy.value = col; sortDir.value = col === 'symbol' ? 'asc' : 'desc'; }
}
const sortIcon = (col) => sortBy.value !== col ? 'bi-chevron-expand' : (sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down');

const rows = computed(() => {
    if (!result.value) return [];
    const q = search.value.trim().toLowerCase();
    let list = result.value.results;
    if (q) list = list.filter(r => r.symbol.toLowerCase().includes(q) || (r.name || '').toLowerCase().includes(q) || r.lists.some(l => l.toLowerCase().includes(q)));
    if (ratingFilter.value === 'none') list = list.filter(r => !r.rating);
    else if (ratingFilter.value > 0)   list = list.filter(r => (r.rating || 0) >= ratingFilter.value);

    const key = sortBy.value, dir = sortDir.value === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => {
        const x = a[key], y = b[key];
        if ((x === null || x === undefined) && (y === null || y === undefined)) return a.symbol.localeCompare(b.symbol);
        if (x === null || x === undefined) return 1;
        if (y === null || y === undefined) return -1;
        const c = typeof x === 'string' ? x.localeCompare(y) : x - y;
        return c ? c * dir : a.symbol.localeCompare(b.symbol);
    });
});

// the breakout month sorts by its age (a month name does not sort), shown as "Aug 2026 · 3 months ago"
const ago = (n) => n === 1 ? 'last month' : n + ' months ago';

// ─── Stars (saved on the symbol's watchlist rows, same as the Watchlist page) ─

function rate(r, stars) {
    result.value.results.forEach((x) => { if (x.exchange === r.exchange && x.symbol === r.symbol) x.rating = stars || null; });
    DBService.postData('/api/charts/rate', { symbol: r.symbol, exchange: r.exchange, rating: stars }).then((d) => { if (!d.success) alert(d.message); });
}

function onChartRated({ exchange, symbol, rating }) {
    if (!result.value) return;
    result.value.results.forEach((x) => { if (x.exchange === exchange && x.symbol === symbol) x.rating = rating || null; });
}

// ─── Chart popup (monthly chart is the one this scanner is about) ────

const showChartPopup = ref(false);
const popupItems = ref([]);
const popupStart = ref(0);

function openChart(r) {
    popupItems.value = rows.value.map(x => ({ exchange: x.exchange, symbol: x.symbol, name: x.name }));
    popupStart.value = Math.max(0, popupItems.value.findIndex(x => x.exchange === r.exchange && x.symbol === r.symbol));
    showChartPopup.value = true;
}

// ─── "Why is / isn't this symbol in the results?" ────────────────────

const checkSymbol  = ref('');
const checking     = ref(false);
const checkResult  = ref(null);

function check() {
    const symbol = checkSymbol.value.trim();
    if (!symbol || checking.value) return;
    checking.value = true;
    checkResult.value = null;
    DBService.postData('/api/stock-scanner/check', { scanner: scannerKey.value, symbol }).then((d) => {
        checking.value = false;
        if (!d.success) { alert(d.message); return; }
        checkResult.value = d;
    }).catch(() => { checking.value = false; });
}

const syncedText = computed(() => fresh.value && fresh.value.last_synced ? fmtDate(fresh.value.last_synced.slice(0, 10)) + ' ' + fresh.value.last_synced.slice(11, 16) : 'never');
</script>

<template>
    <PageHeader
        title="Stock Scanner"
        subtitle="Run a fixed pattern over your whole watchlist, on the stored candles"
        placeholder="Filter the results…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="candleSync.syncing.value" title="Fetch candles from Kite for the symbols that are not up to date (the evening job does this by itself at 16:30 on weekdays)" @click="candleSync.start('/api/charts/sync')">
                <span v-if="candleSync.syncing.value" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-cloud-download"></i>
                {{ candleSync.syncing.value ? 'Syncing…' : 'Sync candles' }}
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="!overview" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</div>

        <template v-else>
            <div v-if="candleSync.note.value" class="pf-alert" :class="candleSync.noteOk.value ? 'pf-alert-ok' : 'pf-alert-bad'">
                <i class="bi" :class="candleSync.noteOk.value ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
                <div class="flex-grow-1">Candle sync: {{ candleSync.note.value }}</div>
                <button class="tbl-icon-btn" @click="candleSync.note.value = ''"><i class="bi bi-x-lg"></i></button>
            </div>
            <div v-if="fresh && fresh.stale > 0" class="pf-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>
                    {{ fresh.stale }} of {{ fresh.total }} symbol{{ fresh.total === 1 ? '' : 's' }} not synced since the last market close — the results may use old candles.
                    Use <strong>Sync candles</strong> first (last sync: {{ syncedText }}).
                </div>
            </div>

            <!-- the scanner and what to run it on -->
            <div class="card-panel mb-3">
                <div class="p-3">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <select v-model="scannerKey" class="form-select form-select-sm fw-semibold" style="width:auto;min-width:240px" title="Scanner">
                            <option v-for="s in overview.scanners" :key="s.key" :value="s.key">{{ s.name }} · {{ s.chart }} chart</option>
                        </select>
                        <select v-model="listId" class="form-select form-select-sm" style="width:auto" title="Which watchlist to scan">
                            <option value="all">All watchlists</option>
                            <option v-for="w in overview.watchlists" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                        <button class="btn-new-filing" :disabled="running || !scannerKey" @click="run">
                            <span v-if="running" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-play-fill me-1"></i>
                            {{ running ? 'Scanning…' : 'Run scanner' }}
                        </button>
                        <span v-if="result" class="text-muted" style="font-size:12.5px">
                            ran {{ result.ran_at.slice(11, 16) }} · {{ result.scanned }} symbols scanned
                            <template v-if="result.no_candles || result.no_data"> · {{ result.no_candles + result.no_data }} skipped (no candles, or under 22 months of history)</template>
                        </span>
                    </div>

                    <template v-if="scanner">
                        <p class="mt-3 mb-1" style="font-size:13.5px">{{ scanner.summary }}</p>
                        <a href="#" class="small" @click.prevent="showRules = !showRules">{{ showRules ? 'Hide' : 'Show' }} the rules</a>
                        <ol v-if="showRules" class="small text-muted mt-2 mb-0 ps-3">
                            <li v-for="(r, i) in scanner.rules" :key="i">{{ r }}</li>
                        </ol>
                    </template>
                </div>
            </div>

            <!-- results -->
            <div v-if="result" class="card-panel mb-3">
                <div class="d-flex align-items-center gap-2 m-3">
                    <span class="fw-semibold">{{ result.matched }} match{{ result.matched === 1 ? '' : 'es' }}</span>
                    <span v-if="rows.length !== result.matched" class="text-muted small">({{ rows.length }} shown)</span>
                    <div class="ms-auto d-flex gap-2">
                        <select v-model="ratingFilter" class="form-select form-select-sm" style="width:auto" title="Filter by your star rating">
                            <option :value="0">Any rating</option>
                            <option :value="5">5 stars</option>
                            <option :value="4">4 stars +</option>
                            <option :value="3">3 stars +</option>
                            <option :value="2">2 stars +</option>
                            <option value="none">Not rated</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="th-sort" :class="{ sorted: sortBy === 'rating' }" style="min-width:104px" @click="sort('rating')">Rating <i class="bi" :class="sortIcon('rating')"></i></th>
                                <th class="th-sort" :class="{ sorted: sortBy === 'symbol' }" style="min-width:150px" @click="sort('symbol')">Symbol <i class="bi" :class="sortIcon('symbol')"></i></th>
                                <th class="th-sort" :class="{ sorted: sortBy === 'months_ago' }" style="min-width:150px" title="The breakout month" @click="sort('months_ago')">Breakout <i class="bi" :class="sortIcon('months_ago')"></i></th>
                                <th class="th-sort text-end" :class="{ sorted: sortBy === 'body_pct' }" title="Size of the breakout candle's body" @click="sort('body_pct')">Candle <i class="bi" :class="sortIcon('body_pct')"></i></th>
                                <th class="th-sort text-end" :class="{ sorted: sortBy === 'volume_x' }" title="Breakout volume against the average of the 12 months before" @click="sort('volume_x')">Volume <i class="bi" :class="sortIcon('volume_x')"></i></th>
                                <th class="th-sort text-end" :class="{ sorted: sortBy === 'pullback_pct' }" title="How far the latest close is below the breakout close" @click="sort('pullback_pct')">Pullback <i class="bi" :class="sortIcon('pullback_pct')"></i></th>
                                <th class="th-sort text-end" :class="{ sorted: sortBy === 'rsi9' }" title="Monthly RSI (9) — for information, not a rule" @click="sort('rsi9')">RSI 9 <i class="bi" :class="sortIcon('rsi9')"></i></th>
                                <th class="th-sort text-end" :class="{ sorted: sortBy === 'price' }" title="Last stored daily close" @click="sort('price')">Price <i class="bi" :class="sortIcon('price')"></i></th>
                                <th>Watchlist</th>
                                <th class="text-end">Chart</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!rows.length">
                                <td colspan="10" class="text-center py-5 text-muted">{{ result.matched ? 'No results match the filters' : 'No symbol in the watchlist fits this pattern right now' }}</td>
                            </tr>
                            <tr v-for="r in rows" :key="r.exchange + r.symbol">
                                <td class="text-nowrap"><StarRating :model-value="r.rating" @rate="(n) => rate(r, n)" /></td>
                                <td>
                                    <button class="btn btn-link p-0 cell-primary mono text-start" style="text-decoration:none" title="Open the charts" @click="openChart(r)">{{ r.symbol }}</button>
                                    <div v-if="r.name" class="cell-secondary">{{ r.name }}</div>
                                    <div v-if="r.exchange !== 'NSE'" class="cell-secondary mono text-primary">{{ r.exchange }}</div>
                                </td>
                                <td class="text-nowrap" style="font-size:13px">{{ r.breakout_month }} <span class="text-muted">· {{ ago(r.months_ago) }}</span></td>
                                <td class="text-num">+{{ r.body_pct }}%</td>
                                <td class="text-num">{{ r.volume_x }}×</td>
                                <td class="text-num" :title="r.retrace_pct !== null ? r.retrace_pct + '% of the breakout candle given back' : ''">−{{ r.pullback_pct }}%</td>
                                <td class="text-num">{{ r.rsi9 !== null ? r.rsi9 : '—' }}</td>
                                <td class="text-num">
                                    <template v-if="r.price !== null">{{ fmtPrice(r.price) }}</template><span v-else class="text-muted">—</span>
                                    <div v-if="r.price_date" class="cell-secondary">{{ fmtDate(r.price_date) }}</div>
                                </td>
                                <td style="font-size:12.5px">{{ r.lists.join(', ') }}</td>
                                <td class="text-end"><button class="tbl-icon-btn" title="Open the charts" @click="openChart(r)"><i class="bi bi-bar-chart-line"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- why is / isn't a symbol here -->
            <div class="card-panel">
                <div class="p-3">
                    <div class="fw-semibold mb-2">Check a symbol</div>
                    <form class="d-flex gap-2 align-items-center" @submit.prevent="check">
                        <input v-model="checkSymbol" class="form-control form-control-sm mono text-uppercase" style="width:200px" placeholder="e.g. RBA" maxlength="50">
                        <button class="btn-secondary-sm" type="submit" :disabled="checking || !checkSymbol.trim()">
                            <span v-if="checking" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-search"></i> Check
                        </button>
                        <span class="text-muted small">Shows each rule, passed or not, for one symbol (it must be in a watchlist with its candles synced).</span>
                    </form>

                    <div v-if="checkResult" class="mt-3">
                        <div v-if="checkResult.status === 'no_data'" class="text-muted">{{ checkResult.message }}</div>
                        <template v-else>
                            <div class="mb-2">
                                <span class="mono fw-semibold">{{ checkResult.symbol }}</span>
                                <span class="status-chip ms-2" :class="checkResult.status === 'match' ? 'chip-green' : 'chip-amber'">
                                    {{ checkResult.status === 'match' ? 'Match' : 'No match' }} · {{ checkResult.passed }} of {{ checkResult.total }} rules
                                </span>
                                <span v-if="checkResult.facts" class="text-muted small ms-2">breakout candidate: {{ checkResult.facts.breakout_month }}</span>
                            </div>
                            <table class="table table-sm mb-0" style="max-width:760px">
                                <tbody>
                                    <tr v-for="(c, i) in checkResult.checks" :key="i">
                                        <td style="width:28px"><i class="bi" :class="c.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'"></i></td>
                                        <td>{{ c.rule }}</td>
                                        <td class="text-muted small">{{ c.detail }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" @rated="onChartRated" />
</template>
