<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtDate } from '@/Service/Utils/Format.js';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';

// Your Zerodha executions: loaded from a tradebook file (Console → Reports → Tradebook, CSV or Excel) or synced from Kite
// (today's trades; the daily `zerodha:sync-trades` command does the same). Partial fills of one order show as one line.
const loading  = ref(false);
const loaded   = ref(false);
const orders   = ref([]);
const summary  = ref({});
const imports  = ref([]);
const total    = ref(0);
const pages    = ref(1);
const perPage  = ref(50);

const tab    = ref('orders');       // 'orders' | 'imports'
const search = ref('');
const side   = ref('');
const from   = ref('');
const to     = ref('');
const page   = ref(1);
const sortBy  = ref('date');
const sortDir = ref('desc');
const open   = ref({});             // order key → its fills are expanded

let searchTimer = null;
let reqId = 0;                      // only the newest answer is shown (typing fast fires several)

function fetchData() {
    const mine = ++reqId;
    loading.value = true;
    return DBService.postData('/api/zerodha-trades/list', {
        search: search.value, side: side.value, from: from.value, to: to.value, page: page.value, sort: sortBy.value, dir: sortDir.value,
    }).then((data) => {
        if (mine !== reqId) return;
        loading.value = false;
        loaded.value = true;
        if (!data.success) { alert(typeof data.message === 'string' ? data.message : 'Could not load the trades'); return; }
        orders.value  = data.orders;
        summary.value = data.summary;
        imports.value = data.imports;
        total.value   = data.total;
        pages.value   = data.pages;
        perPage.value = data.per_page;
        if (page.value > data.pages) { page.value = data.pages; fetchData(); }
    });
}

onMounted(() => fetchData());

function onSearch(q) {
    search.value = q;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page.value = 1; fetchData(); }, 300);
}

function refilter() { page.value = 1; fetchData(); }

function clearFilters() {
    side.value = ''; from.value = ''; to.value = '';
    refilter();
}

function goto(p) {
    if (p < 1 || p > pages.value || p === page.value) return;
    page.value = p;
    fetchData();
}

function sort(col) {
    if (sortBy.value === col) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    else { sortBy.value = col; sortDir.value = col === 'symbol' ? 'asc' : 'desc'; }
    refilter();
}
const sortIcon = (col) => sortBy.value !== col ? 'bi-arrow-down-up' : (sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down');

const filtered   = computed(() => !!(search.value || side.value || from.value || to.value));
const money      = (v) => '₹' + fmtNum(v, 0);
const firstShown = computed(() => total.value ? (page.value - 1) * perPage.value + 1 : 0);
const lastShown  = computed(() => Math.min(total.value, page.value * perPage.value));

// ─── Upload + sync ───────────────────────────────────────────────────

const fileInput = ref(null);
const uploading = ref(false);
const syncing   = ref(false);
const result    = ref(null);        // { ok, message, conflicts[], skipped[] } shown above the table

function showResult(data, fallback) {
    const message = typeof data.message === 'string' ? data.message : fallback;
    result.value = {
        ok: data.success && !data.conflicts && !data.skipped,
        failed: !data.success,
        message,
        conflicts: data.conflict_list || [],
        skipped: data.skipped_list || [],
    };
}

function pickFile() {
    fileInput.value.click();
}

function onFile(e) {
    const file = e.target.files[0];
    e.target.value = '';                       // so the same file can be chosen again
    if (!file) return;

    const form = new FormData();
    form.append('file', file);

    uploading.value = true;
    result.value = null;
    DBService.postData('/api/zerodha-trades/upload', form).then((data) => {
        uploading.value = false;
        showResult(data, 'The upload failed — the file may be larger than the server allows');
        if (data.success) { tab.value = 'orders'; page.value = 1; fetchData(); }
    });
}

function syncKite() {
    syncing.value = true;
    result.value = null;
    DBService.postData('/api/zerodha-trades/sync', {}).then((data) => {
        syncing.value = false;
        showResult(data, 'Could not reach Kite');
        if (data.success) { page.value = 1; fetchData(); }
    });
}

function undo(i) {
    const what = i.source === 'api' ? 'the Kite sync' : `“${i.file_name}”`;
    if (!confirm(`Undo ${what} from ${i.at}?\n\nThe ${fmtNum(i.added)} execution${i.added === 1 ? '' : 's'} it added will be removed. Trades that were already stored are not touched.`)) return;
    DBService.postData('/api/zerodha-trades/undo', { id: i.id }).then((data) => {
        if (!data.success) { alert(data.message); return; }
        result.value = { ok: true, failed: false, message: data.message, conflicts: [], skipped: [] };
        fetchData();
    });
}

// ─── Chart popup ─────────────────────────────────────────────────────

const showChartPopup = ref(false);
const popupItems     = ref([]);
const popupStart     = ref(0);

function openChart(row) {
    const seen = [];
    orders.value.forEach(o => { if (!seen.some(s => s.symbol === o.symbol && s.exchange === o.exchange)) seen.push({ exchange: o.exchange, symbol: o.symbol }); });
    popupItems.value = seen;
    popupStart.value = Math.max(0, seen.findIndex(s => s.symbol === row.symbol && s.exchange === row.exchange));
    showChartPopup.value = true;
}

const COLS = 9;
</script>

<template>
    <PageHeader
        title="Zerodha Trades"
        subtitle="Every execution Zerodha reported — from your tradebook file and from Kite"
        placeholder="Search symbol…"
        @search="onSearch"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="syncing || uploading" title="Save today's executions from Kite (Kite only returns the current trading day)" @click="syncKite">
                <span v-if="syncing" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-arrow-repeat"></i>
                Sync from Kite
            </button>
            <button class="btn-new-filing" :disabled="uploading || syncing" @click="pickFile">
                <span v-if="uploading" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-upload me-1"></i>
                Upload tradebook
            </button>
            <input ref="fileInput" type="file" accept=".csv,.xlsx,.xls,text/csv" class="d-none" @change="onFile" />
        </template>
    </PageHeader>

    <div class="page-canvas">

        <!-- what the last upload / sync did -->
        <div v-if="result" class="pf-alert zt-result" :class="{ 'pf-alert-ok': result.ok, 'pf-alert-bad': result.failed }">
            <i class="bi" :class="result.failed ? 'bi-x-circle-fill' : (result.ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill')"></i>
            <div class="flex-grow-1">
                <div>{{ result.message }}</div>
                <ul v-if="result.conflicts.length" class="zt-notes">
                    <li v-for="(c, i) in result.conflicts" :key="'c' + i">{{ c }}</li>
                </ul>
                <ul v-if="result.skipped.length" class="zt-notes">
                    <li v-for="(s, i) in result.skipped" :key="'s' + i">line {{ s.line }}: {{ s.reason }}</li>
                </ul>
            </div>
            <button class="tbl-icon-btn" title="Dismiss" @click="result = null"><i class="bi bi-x-lg"></i></button>
        </div>

        <!-- Summary -->
        <div class="pf-summary">
            <div class="pf-stat">
                <div class="pf-stat-label">Orders</div>
                <div class="pf-stat-value">{{ fmtNum(summary.orders) }}</div>
                <div class="pf-stat-sub">{{ fmtNum(summary.fills) }} executions{{ filtered ? ' (filtered)' : '' }}</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Symbols</div>
                <div class="pf-stat-value">{{ fmtNum(summary.symbols) }}</div>
                <div class="pf-stat-sub">traded</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Bought</div>
                <div class="pf-stat-value">{{ money(summary.buy_value) }}</div>
                <div class="pf-stat-sub">total value of buys</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Sold</div>
                <div class="pf-stat-value">{{ money(summary.sell_value) }}</div>
                <div class="pf-stat-sub">total value of sells</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Period</div>
                <div class="pf-stat-value" style="font-size:15px">{{ summary.first_date ? fmtDate(summary.first_date) : '—' }}</div>
                <div class="pf-stat-sub">{{ summary.last_date ? 'to ' + fmtDate(summary.last_date) : 'nothing loaded yet' }}</div>
            </div>
        </div>

        <div class="card-panel">

            <div class="pf-tabs">
                <button class="pf-tab" :class="{ active: tab === 'orders' }" @click="tab = 'orders'">Orders <span class="pf-tab-count">{{ fmtNum(total) }}</span></button>
                <button class="pf-tab" :class="{ active: tab === 'imports' }" @click="tab = 'imports'">Imports <span class="pf-tab-count">{{ imports.length }}</span></button>
                <span class="pf-tab-spacer"></span>
                <template v-if="tab === 'orders'">
                    <select v-model="side" class="form-select form-select-sm zt-filter" @change="refilter">
                        <option value="">Buys &amp; sells</option>
                        <option value="buy">Buys</option>
                        <option value="sell">Sells</option>
                    </select>
                    <input v-model="from" type="date" class="form-control form-control-sm zt-filter" title="From date" @change="refilter" />
                    <input v-model="to" type="date" class="form-control form-control-sm zt-filter" title="To date" @change="refilter" />
                    <button v-if="filtered" class="btn-secondary-sm" @click="clearFilters"><i class="bi bi-x-lg"></i> Clear</button>
                </template>
            </div>

            <!-- Orders -->
            <div v-if="tab === 'orders'" class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:34px"></th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'date' }" @click="sort('date')">Date <i class="bi" :class="sortIcon('date')"></i></th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'symbol' }" style="min-width:130px" @click="sort('symbol')">Symbol <i class="bi" :class="sortIcon('symbol')"></i></th>
                            <th>Side</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end" title="Total value ÷ quantity, across the order's partial fills">Avg price</th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'value' }" @click="sort('value')">Value <i class="bi" :class="sortIcon('value')"></i></th>
                            <th class="text-end" title="How many separate executions the order was filled in">Fills</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!loaded">
                            <td :colspan="COLS" class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</td>
                        </tr>
                        <tr v-else-if="!orders.length">
                            <td :colspan="COLS" class="text-center py-5 text-muted">
                                <template v-if="filtered">No trades match these filters</template>
                                <template v-else>
                                    No Zerodha trades yet.<br>
                                    Download your tradebook from Console (Reports → Tradebook) and use <strong>Upload tradebook</strong>, or <strong>Sync from Kite</strong> for today's trades.
                                </template>
                            </td>
                        </tr>

                        <template v-else v-for="o in orders" :key="o.key">
                            <tr :style="loading ? 'opacity:.6' : ''">
                                <td>
                                    <button v-if="o.fills > 1" class="tbl-icon-btn" :title="open[o.key] ? 'Hide the fills' : 'Show the fills'" @click="open[o.key] = !open[o.key]">
                                        <i class="bi" :class="open[o.key] ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                                    </button>
                                </td>
                                <td class="text-nowrap">{{ fmtDate(o.date) }}<div v-if="o.time" class="cell-secondary mono" style="margin:0">{{ o.time }}</div></td>
                                <td>
                                    <button class="btn btn-link p-0 cell-primary mono text-start" style="text-decoration:none" title="Open the charts" @click="openChart(o)">{{ o.symbol }}</button>
                                    <div class="cell-secondary mono" :class="{ 'text-primary': o.exchange !== 'NSE' }">{{ o.exchange }}<template v-if="o.isin"> · {{ o.isin }}</template></div>
                                </td>
                                <td><span class="status-chip" :class="o.side === 'buy' ? 'chip-green' : 'chip-red'" style="text-transform:uppercase">{{ o.side }}</span></td>
                                <td class="text-num">{{ fmtNum(o.qty) }}</td>
                                <td class="text-num">{{ fmtPrice(o.avg_price) }}</td>
                                <td class="text-num">₹{{ fmtNum(o.value, 2) }}</td>
                                <td class="text-num text-muted">{{ o.fills }}</td>
                                <td>
                                    <span class="status-chip" :class="o.source === 'api' ? 'chip-amber' : 'chip-neutral'" :title="o.source === 'api' ? 'Synced from Kite' : 'From an uploaded tradebook'">{{ o.source === 'api' ? 'Kite' : 'File' }}</span>
                                    <span v-if="o.product" class="cell-secondary ms-1">{{ o.product }}</span>
                                </td>
                            </tr>

                            <tr v-if="open[o.key]" class="trade-detail-row">
                                <td></td>
                                <td :colspan="COLS - 1">
                                    <table class="trade-actions">
                                        <thead><tr><th>Time</th><th>Trade ID</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                            <tr v-for="f in o.fill_list" :key="f.id">
                                                <td class="mono">{{ f.at || '—' }}</td>
                                                <td class="mono text-muted">{{ f.trade_id }}</td>
                                                <td class="text-num">{{ fmtNum(f.qty) }}</td>
                                                <td class="text-num">{{ fmtPrice(f.price) }}</td>
                                                <td class="text-num">₹{{ fmtNum(f.qty * f.price, 2) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div v-if="total > perPage" class="zt-pager">
                    <span class="text-muted">{{ fmtNum(firstShown) }}–{{ fmtNum(lastShown) }} of {{ fmtNum(total) }} orders</span>
                    <span class="ms-auto d-flex align-items-center gap-2">
                        <button class="btn-secondary-sm" :disabled="page <= 1" @click="goto(1)"><i class="bi bi-chevron-bar-left"></i></button>
                        <button class="btn-secondary-sm" :disabled="page <= 1" @click="goto(page - 1)"><i class="bi bi-chevron-left"></i> Prev</button>
                        <span>Page {{ page }} of {{ pages }}</span>
                        <button class="btn-secondary-sm" :disabled="page >= pages" @click="goto(page + 1)">Next <i class="bi bi-chevron-right"></i></button>
                        <button class="btn-secondary-sm" :disabled="page >= pages" @click="goto(pages)"><i class="bi bi-chevron-bar-right"></i></button>
                    </span>
                </div>
            </div>

            <!-- Imports -->
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th><th>From</th>
                            <th class="text-end">Rows</th><th class="text-end">New</th>
                            <th class="text-end" title="Already stored from an earlier upload or sync">Already had</th>
                            <th class="text-end" title="Same trade id but different details: left as stored">Differing</th>
                            <th class="text-end" title="Rows that could not be read">Unreadable</th>
                            <th>Trades from</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!imports.length">
                            <td colspan="9" class="text-center py-5 text-muted">Nothing imported yet.</td>
                        </tr>
                        <tr v-for="i in imports" :key="i.id">
                            <td class="text-nowrap">{{ i.at }}</td>
                            <td>
                                <span v-if="i.source === 'api'" class="status-chip chip-amber">Kite sync</span>
                                <template v-else><span class="status-chip chip-neutral">File</span> <span class="cell-secondary" style="display:inline">{{ i.file_name }}</span></template>
                            </td>
                            <td class="text-num">{{ fmtNum(i.rows_read) }}</td>
                            <td class="text-num fw-semibold">{{ fmtNum(i.added) }}</td>
                            <td class="text-num text-muted">{{ i.duplicates ? fmtNum(i.duplicates) : '—' }}</td>
                            <td class="text-num"><span v-if="i.conflicts" class="pnl-neg">{{ fmtNum(i.conflicts) }}</span><span v-else class="text-muted">—</span></td>
                            <td class="text-num"><span v-if="i.skipped" class="pnl-neg">{{ fmtNum(i.skipped) }}</span><span v-else class="text-muted">—</span></td>
                            <td class="text-nowrap">{{ i.first_date ? fmtDate(i.first_date) : '—' }}<template v-if="i.last_date && i.last_date !== i.first_date"> – {{ fmtDate(i.last_date) }}</template></td>
                            <td class="text-end">
                                <button class="tbl-icon-btn tbl-icon-btn-danger" :title="i.added ? `Remove the ${i.added} executions this import added` : 'Remove this entry from the history'" @click="undo(i)"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" />
</template>
