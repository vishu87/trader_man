<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtPct, fmtDate, pnlClass } from '@/Service/Utils/Format.js';
import BucketForm from '@/Includes/Portfolio/BucketForm.vue';
import HoldingForm from '@/Includes/Portfolio/HoldingForm.vue';
import UploadAside from '@/Includes/Portfolio/UploadAside.vue';
import NotMappedTable from '@/Includes/Portfolio/NotMappedTable.vue';
import RemovalTable from '@/Includes/Portfolio/RemovalTable.vue';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';

const loading    = ref(false);
const buckets    = ref([]);
const holdings   = ref([]);
const notMapped  = ref([]);
const removals   = ref([]);
const ltpError   = ref(null);

// 'all' | <bucket id> | 'not_mapped' | 'removal'
const tab    = ref('all');
const search = ref('');

// 'all' | 'checked' | 'unchecked'
const checkFilter = ref('all');

// 'all' | 'followed'
const followFilter = ref('all');

// Only affects the summary tiles (the list always shows every stock):
// 'without' (default) = tracking stocks are left out of the totals, 'with' = they are counted
const trackingView = ref('without');

const sortBy  = ref('symbol');
const sortDir = ref('asc');

const showBucketForm  = ref(false);
const editBucket      = ref(null);
const showHoldingForm = ref(false);
const editHolding     = ref(null);
const showUpload      = ref(false);
const exporting       = ref(false);

// ─── Data ────────────────────────────────────────────────────────────

function fetchData(refresh = false) {
    loading.value = true;
    return DBService.postData('/api/portfolio/data', { refresh: refresh ? 1 : 0 }).then((data) => {
        if (data.success) {
            buckets.value   = data.buckets;
            holdings.value  = data.holdings;
            today.value     = data.today;
            notMapped.value = data.not_mapped;
            removals.value  = data.removals;
            ltpError.value  = data.ltp_error;

            // the active bucket may have been deleted
            if (typeof tab.value === 'number' && !buckets.value.find(b => b.id === tab.value)) {
                tab.value = 'all';
            }
        } else {
            alert(data.message);
        }
        loading.value = false;
    });
}

onMounted(fetchData);

// ─── Filtering / sorting ─────────────────────────────────────────────

const isHoldingsTab = computed(() => tab.value !== 'not_mapped' && tab.value !== 'removal');
const activeBucket  = computed(() => buckets.value.find(b => b.id === tab.value) || null);

function matches(text) {
    const q = search.value.trim().toLowerCase();
    return !q || String(text).toLowerCase().includes(q);
}

// buckets flagged "exclude from All": still have their own tab, but are left out of the All tab
const excludedIds = computed(() => new Set(buckets.value.filter(b => b.exclude_from_all).map(b => b.id)));
const inAll = (h) => !excludedIds.value.has(h.bucket_id);

// every stock in the active tab, before search / checked filter
const tabRows = computed(() =>
    typeof tab.value === 'number' ? holdings.value.filter(h => h.bucket_id === tab.value) : holdings.value.filter(inAll)
);
const checkedCount = computed(() => tabRows.value.filter(h => h.checked).length);

const rows = computed(() => {
    let list = tabRows.value.filter(h => matches(h.symbol) || matches(h.zerodha_symbol) || matches(h.bucket_name));
    if (checkFilter.value === 'checked')   list = list.filter(h => h.checked);
    if (checkFilter.value === 'unchecked') list = list.filter(h => !h.checked);
    if (followFilter.value === 'followed') list = list.filter(h => h.follow);
    if (viewFilter.value === 'not_today')  list = list.filter(h => h.last_viewed !== today.value);
    if (viewFilter.value === 'today')      list = list.filter(h => h.last_viewed === today.value);

    const key = sortBy.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    // an LTP of -1 means "no price" — treat like null so it always sorts last
    const val = (h) => (key === 'ltp' && h.ltp < 0) ? null : h[key];

    return [...list].sort((a, b) => {
        const x = val(a), y = val(b);
        if (x === null && y === null) return 0;
        if (x === null) return 1;
        if (y === null) return -1;
        if (typeof x === 'string') return x.localeCompare(y) * dir;
        return (x - y) * dir;
    });
});

const filteredNotMapped = computed(() => notMapped.value.filter(r => matches(r.symbol)));
const filteredRemovals  = computed(() => removals.value.filter(r => matches(r.symbol)));

// every stock in the bucket (tracking included) — also what decides whether a bucket can be deleted
function bucketCount(id) {
    return holdings.value.filter(h => h.bucket_id === id).length;
}
const bucketTotal = bucketCount;

const allCount = computed(() => holdings.value.filter(inAll).length);
const trackingListed = computed(() => rows.value.some(h => h.is_tracking));

function sort(col) {
    if (sortBy.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value  = col;
        sortDir.value = (col === 'symbol' || col === 'bucket_name' || col === 'checked') ? 'asc' : 'desc';   // follow: starred first (desc)
    }
}

function sortIcon(col) {
    if (sortBy.value !== col) return 'bi-chevron-expand';
    return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
}

// ─── Summary (rows currently shown) ──────────────────────────────────
// Stocks without an LTP count toward Invested but are left out of Current Value / P&L.

// Tracking stocks are always listed, but only counted here when the "With tracking" toggle is on.

const summary = computed(() => {
    const owned   = trackingView.value === 'with' ? rows.value : rows.value.filter(h => !h.is_tracking);
    const priced  = owned.filter(h => h.current_value !== null);
    const invested = owned.reduce((s, h) => s + h.invested, 0);
    const pricedInvested = priced.reduce((s, h) => s + h.invested, 0);
    const current = priced.reduce((s, h) => s + h.current_value, 0);
    const pnl     = current - pricedInvested;

    return {
        invested,
        current,
        pnl,
        pnlPct: pricedInvested > 0 ? pnl / pricedInvested * 100 : null,
        count: owned.length,
        tracking: rows.value.length - owned.length,
        noLtp: owned.length - priced.length,
    };
});

// ─── Actions ─────────────────────────────────────────────────────────

function openAddBucket() {
    editBucket.value = null;
    showBucketForm.value = true;
}

function openEditBucket() {
    editBucket.value = activeBucket.value;
    showBucketForm.value = true;
}

function onBucketSaved(id) {
    fetchData().then(() => { if (id) tab.value = id; });
}

function deleteBucket() {
    const b = activeBucket.value;
    if (!b) return;
    const n = bucketTotal(b.id);
    if (n > 0) {
        alert(`"${b.name}" still has ${n} stock${n === 1 ? '' : 's'}. A bucket can only be deleted when it is empty.`);
        return;
    }
    if (!confirm(`Delete bucket "${b.name}"?`)) return;
    DBService.postData('/api/portfolio/bucket/delete', { id: b.id }).then((data) => {
        if (!data.success) alert(data.message);
        else { tab.value = 'all'; fetchData(); }
    });
}

function openAddHolding() {
    editHolding.value = null;
    showHoldingForm.value = true;
}

function openEditHolding(h) {
    editHolding.value = h;
    showHoldingForm.value = true;
}

// Set the flag locally first (instant), then save; fall back to a refetch if the save fails
function setChecked(list, checked) {
    const ids = list.map(h => h.id);
    list.forEach(h => { h.checked = checked; });

    DBService.postData('/api/portfolio/holding/check', { ids, checked }).then((data) => {
        if (!data.success) {
            alert(data.message);
            fetchData();
        }
    });
}

// Star / unstar one stock (instant; reverted by a refetch if the save fails)
function toggleFollow(h) {
    const follow = h.follow ? 0 : 1;
    h.follow = follow;
    DBService.postData('/api/portfolio/holding/follow', { ids: [h.id], follow }).then((data) => {
        if (!data.success) {
            alert(data.message);
            fetchData();
        }
    });
}

function toggleChecked(h) {
    setChecked([h], h.checked ? 0 : 1);
}

function bulkChecked(checked) {
    const list = rows.value;
    if (!list.length) return;
    const what = checked ? 'Mark' : 'Unmark';
    if (!confirm(`${what} ${list.length} stock${list.length === 1 ? '' : 's'} as checked?\n\n(Applies to the stocks currently shown.)`)) return;
    setChecked(list, checked);
}

// Export exactly the rows currently shown (search / sort / filters applied), in their displayed order
function doExport(type, list) {
    if (!list.length) return;
    exporting.value = true;
    DBService.postData('/api/portfolio/export', { type, ids: list.map(r => r.id) }).then((data) => {
        exporting.value = false;
        if (data.success) window.open(base_url + '/' + data.export, '_blank');
        else alert(data.message);
    });
}


// ─── Chart popup: monthly + weekly + daily, Previous / Next through the list as it is shown ───

const today          = ref('');      // the server's today, so "viewed today" agrees with the dates it sends
const viewFilter     = ref('all');   // all | not_today | today
const showChartPopup = ref(false);
const popupItems     = ref([]);
const popupStart     = ref(0);

function openChart(row) {
    const seen = new Set();
    const list = [];
    // the symbols in the order they are on screen (filters + sort applied); a symbol held in two places is listed once
    rows.value.forEach((r) => {
        const k = `${r.exchange}:${r.zerodha_symbol}`;
        if (seen.has(k)) return;
        seen.add(k);
        list.push({ exchange: r.exchange, symbol: r.zerodha_symbol });
    });
    popupItems.value = list;
    popupStart.value = Math.max(0, list.findIndex(x => x.exchange === row.exchange && x.symbol === row.zerodha_symbol));
    showChartPopup.value = true;
}

// the popup stamped a chart as viewed: update every row of that symbol, which also re-applies the filter behind it
function onChartViewed({ exchange, symbol, date }) {
    holdings.value.forEach((r) => { if (r.exchange === exchange && r.zerodha_symbol === symbol) r.last_viewed = date; });
}

function onChartFollowed({ exchange, symbol, follow }) {
    holdings.value.forEach((r) => { if (r.exchange === exchange && r.zerodha_symbol === symbol) r.follow = follow; });
}

// Full weekly + daily charts page (opens in a new tab)
const chartsUrl = computed(() => base_url + '/charts?bucket=' + (typeof tab.value === 'number' ? tab.value : 'all'));
const symbolChartUrl = (h) => `${base_url}/charts?symbol=${encodeURIComponent(h.zerodha_symbol)}&exchange=${h.exchange}`;

// Kite web chart; needs the instrument token captured while fetching LTP
function chartUrl(h) {
    return `https://kite.zerodha.com/markets/ext/chart/web/ciq/${h.exchange}/${encodeURIComponent(h.zerodha_symbol)}/${h.instrument_token}`;
}

function deleteHolding(h) {
    if (!confirm(`Delete ${h.symbol} from ${h.bucket_name}?`)) return;
    DBService.postData('/api/portfolio/holding/delete', { id: h.id }).then((data) => {
        if (!data.success) alert(data.message);
        else fetchData();
    });
}

function onUploaded(s) {
    fetchData().then(() => {
        if (s.not_mapped) tab.value = 'not_mapped';
        else if (s.removals) tab.value = 'removal';
    });
}
</script>

<template>
    <PageHeader
        title="Portfolio"
        subtitle="Buckets of stocks with live prices from Kite"
        placeholder="Search symbol / bucket…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" @click="openAddBucket">
                <i class="bi bi-folder-plus"></i> New Bucket
            </button>
            <button class="btn-new-filing" @click="showUpload = true">
                <i class="bi bi-upload me-1"></i> Upload CSV
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="ltpError" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            LTP unavailable — showing -1. {{ ltpError }}
        </div>

        <!-- Summary -->
        <div v-if="isHoldingsTab" class="pf-summary">
            <div class="pf-stat">
                <div class="pf-stat-label">Invested</div>
                <div class="pf-stat-value">₹ {{ fmtNum(summary.invested, 0) }}</div>
                <div class="pf-stat-sub">
                    {{ summary.count }} stock{{ summary.count === 1 ? '' : 's' }}
                    <span v-if="summary.tracking"> · {{ summary.tracking }} tracking not counted</span>
                    <span v-else-if="trackingView === 'with' && trackingListed"> · incl. tracking</span>
                </div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Current Value</div>
                <div class="pf-stat-value">₹ {{ fmtNum(summary.current, 0) }}</div>
                <div v-if="summary.noLtp" class="pf-stat-sub pnl-neg">{{ summary.noLtp }} without LTP (excluded)</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(summary.pnl)">{{ summary.pnl > 0 ? '+' : '' }}₹ {{ fmtNum(summary.pnl, 0) }}</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">P&amp;L %</div>
                <div class="pf-stat-value" :class="pnlClass(summary.pnlPct)">{{ fmtPct(summary.pnlPct) }}</div>
            </div>
        </div>

        <div class="card-panel">

            <!-- Tabs -->
            <div class="pf-tabs">
                <button class="pf-tab" :class="{ active: tab === 'all' }" @click="tab = 'all'">
                    All <span class="pf-tab-count">{{ allCount }}</span>
                </button>
                <button v-for="b in buckets" :key="b.id" class="pf-tab" :class="{ active: tab === b.id }" @click="tab = b.id">
                    {{ b.name }}
                    <i v-if="b.exclude_from_all" class="bi bi-eye-slash" title="Excluded from the All tab" style="font-size:12px;opacity:.7"></i>
                    <span class="pf-tab-count">{{ bucketCount(b.id) }}</span>
                </button>

                <div class="pf-tab-spacer"></div>

                <button class="pf-tab" :class="{ active: tab === 'not_mapped' }" @click="tab = 'not_mapped'">
                    Not Mapped <span class="pf-tab-count" :class="{ warn: notMapped.length }">{{ notMapped.length }}</span>
                </button>
                <button class="pf-tab" :class="{ active: tab === 'removal' }" @click="tab = 'removal'">
                    Removal <span class="pf-tab-count" :class="{ danger: removals.length }">{{ removals.length }}</span>
                </button>
            </div>

            <!-- Holdings toolbar: row 1 = bucket + actions, row 2 = filters -->
            <div v-if="isHoldingsTab" class="pf-toolbar">
                <div class="pf-toolbar-row">
                <div v-if="activeBucket" class="d-flex align-items-center gap-2 pf-bucket-info">
                    <span class="fw-semibold">{{ activeBucket.name }}</span>
                    <span v-if="activeBucket.description" class="text-muted" style="font-size:12.5px">{{ activeBucket.description }}</span>
                    <button class="tbl-icon-btn" title="Edit bucket" @click="openEditBucket"><i class="bi bi-pencil"></i></button>
                    <button
                        class="tbl-icon-btn tbl-icon-btn-danger"
                        :class="{ disabled: bucketTotal(activeBucket.id) > 0 }"
                        :title="bucketTotal(activeBucket.id) > 0 ? `Can't delete — bucket still has ${bucketTotal(activeBucket.id)} stock(s)` : 'Delete bucket'"
                        @click="deleteBucket"
                    ><i class="bi bi-trash"></i></button>
                </div>
                <div class="ms-auto d-flex gap-2 flex-wrap justify-content-end">
                    <a class="btn-secondary-sm" :href="chartsUrl" target="_blank" rel="noopener" title="Weekly + daily charts for every stock here">
                        <i class="bi bi-bar-chart-line"></i> Charts
                    </a>
                    <button class="btn-secondary-sm" :disabled="exporting || !rows.length" @click="doExport('holdings', rows)">
                        <span v-if="exporting" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="bi bi-download"></i>
                        Export
                    </button>
                    <button class="btn-secondary-sm" :disabled="!rows.length" @click="bulkChecked(1)">
                        <i class="bi bi-check2-square"></i> Check all
                    </button>
                    <button class="btn-secondary-sm" :disabled="!rows.length" @click="bulkChecked(0)">
                        <i class="bi bi-square"></i> Uncheck all
                    </button>
                    <button class="btn-secondary-sm" :disabled="loading" @click="fetchData(true)">
                        <span v-if="loading" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="bi bi-arrow-clockwise"></i>
                        Refresh LTP
                    </button>
                    <button class="btn-secondary-sm" :disabled="!buckets.length" @click="openAddHolding">
                        <i class="bi bi-plus-lg"></i> Add Stock
                    </button>
                </div>
                </div>
                <div class="pf-toolbar-row">
                <div class="d-flex align-items-center gap-2">
                    <span class="status-chip" :class="checkedCount === tabRows.length && tabRows.length ? 'chip-green' : 'chip-neutral'">
                        {{ checkedCount }} / {{ tabRows.length }} checked
                    </span>
                    <select v-model="trackingView" class="form-select form-select-sm" style="width:auto" title="Whether tracking stocks are counted in the totals above">
                        <option value="without">Totals: without tracking</option>
                        <option value="with">Totals: with tracking</option>
                    </select>
                    <select v-model="viewFilter" class="form-select form-select-sm" style="width:auto" title="Charts you have (not) opened in the popup today">
                        <option value="all">Viewed or not</option>
                        <option value="not_today">Not viewed today</option>
                        <option value="today">Viewed today</option>
                    </select>
                    <select v-model="followFilter" class="form-select form-select-sm" style="width:auto" title="Show only the stocks you follow">
                        <option value="all">All stocks</option>
                        <option value="followed">Followed only</option>
                    </select>
                    <select v-model="checkFilter" class="form-select form-select-sm" style="width:auto" title="Filter by checked">
                        <option value="all">All</option>
                        <option value="checked">Checked</option>
                        <option value="unchecked">Not checked</option>
                    </select>
                </div>
                </div>
            </div>

            <!-- Not mapped / Removal toolbar -->
            <div v-else class="d-flex align-items-center gap-2 m-3">
                <span class="status-chip" :class="tab === 'not_mapped' ? 'chip-amber' : 'chip-red'">
                    {{ tab === 'not_mapped' ? filteredNotMapped.length : filteredRemovals.length }} stock(s)
                </span>
                <div class="ms-auto">
                    <button
                        class="btn-secondary-sm" :disabled="exporting || !(tab === 'not_mapped' ? filteredNotMapped : filteredRemovals).length"
                        @click="tab === 'not_mapped' ? doExport('not_mapped', filteredNotMapped) : doExport('removal', filteredRemovals)"
                    >
                        <span v-if="exporting" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="bi bi-download"></i>
                        Export
                    </button>
                </div>
            </div>

            <!-- Holdings table -->
            <div v-if="isHoldingsTab" class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="th-sort" :class="{ sorted: sortBy === 'checked' }" @click="sort('checked')" style="width:48px" title="Checked">
                                <i class="bi" :class="sortBy === 'checked' ? sortIcon('checked') : 'bi-check2-square'"></i>
                            </th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'follow' }" @click="sort('follow')" style="width:44px" title="Follow">
                                <i class="bi" :class="sortBy === 'follow' ? sortIcon('follow') : 'bi-star'"></i>
                            </th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'symbol' }" @click="sort('symbol')" style="min-width:150px">
                                Symbol <i class="bi" :class="sortIcon('symbol')"></i>
                            </th>
                            <th v-if="tab === 'all'" class="th-sort" :class="{ sorted: sortBy === 'bucket_name' }" @click="sort('bucket_name')">
                                Bucket <i class="bi" :class="sortIcon('bucket_name')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'quantity' }" @click="sort('quantity')">
                                Qty <i class="bi" :class="sortIcon('quantity')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'avg_price' }" @click="sort('avg_price')">
                                Avg Price <i class="bi" :class="sortIcon('avg_price')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'ltp' }" @click="sort('ltp')">
                                LTP <i class="bi" :class="sortIcon('ltp')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'invested' }" @click="sort('invested')">
                                Invested <i class="bi" :class="sortIcon('invested')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'current_value' }" @click="sort('current_value')">
                                Current Value <i class="bi" :class="sortIcon('current_value')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'pnl' }" @click="sort('pnl')">
                                P&amp;L <i class="bi" :class="sortIcon('pnl')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'pnl_pct' }" @click="sort('pnl_pct')">
                                P&amp;L % <i class="bi" :class="sortIcon('pnl_pct')"></i>
                            </th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'last_viewed' }" @click="sort('last_viewed')" style="min-width:110px" title="When you last opened this chart in the popup">
                                Last Viewed <i class="bi" :class="sortIcon('last_viewed')"></i>
                            </th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading && !holdings.length">
                            <td :colspan="tab === 'all' ? 13 : 12" class="text-center py-5 text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading…
                            </td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td :colspan="tab === 'all' ? 13 : 12" class="text-center py-5 text-muted">
                                {{ search ? 'No stocks match your search' : (buckets.length ? 'No stocks yet' : 'Create a bucket to get started') }}
                            </td>
                        </tr>
                        <template v-else>
                            <tr v-for="h in rows" :key="h.id" :class="{ 'pf-row-checked': h.checked }">
                                <td>
                                    <input
                                        type="checkbox" class="pf-check"
                                        :checked="!!h.checked" @change="toggleChecked(h)"
                                        :title="h.checked ? 'Checked — click to unmark' : 'Mark as checked'"
                                    />
                                </td>
                                <td>
                                    <button class="pf-star" :class="{ on: h.follow }" :title="h.follow ? 'Following — click to unfollow' : 'Follow (show in followed charts)'" @click="toggleFollow(h)">
                                        <i class="bi" :class="h.follow ? 'bi-star-fill' : 'bi-star'"></i>
                                    </button>
                                </td>
                                <td>
                                    <span class="cell-primary mono">{{ h.symbol }}</span>
                                    <span v-if="h.is_tracking" class="status-chip chip-amber ms-2" title="Tracking only — counted in totals only when 'Totals: with tracking' is selected">Tracking</span>
                                    <div class="cell-secondary mono" :class="{ 'text-primary': h.exchange !== 'NSE' }" title="Exchange : Zerodha symbol used for LTP">{{ h.exchange }}:{{ h.zerodha_symbol }}</div>
                                </td>
                                <td v-if="tab === 'all'" style="font-size:13px">{{ h.bucket_name }}</td>
                                <td class="text-num">{{ fmtNum(h.quantity) }}</td>
                                <td class="text-num">{{ fmtPrice(h.avg_price) }}</td>
                                <td class="text-num">
                                    <span
                                        v-if="h.ltp < 0" class="status-chip chip-red"
                                        :title="`No LTP for ${h.exchange}:${h.zerodha_symbol}`"
                                    >-1</span>
                                    <template v-else>{{ fmtPrice(h.ltp) }}</template>
                                </td>
                                <td class="text-num">{{ fmtPrice(h.invested) }}</td>
                                <td class="text-num">{{ fmtPrice(h.current_value) }}</td>
                                <td class="text-num" :class="pnlClass(h.pnl)">{{ fmtPrice(h.pnl) }}</td>
                                <td class="text-num">
                                    <span v-if="h.pnl_pct !== null" class="pnl-badge" :class="pnlClass(h.pnl_pct)">{{ fmtPct(h.pnl_pct) }}</span>
                                    <template v-else>—</template>
                                </td>
                                <td class="text-nowrap" style="font-size:12.5px">
                                    <span v-if="h.last_viewed && h.last_viewed === today" class="status-chip chip-green" title="You looked at this chart today">Today</span>
                                    <span v-else-if="h.last_viewed" class="text-muted">{{ fmtDate(h.last_viewed) }}</span>
                                    <span v-else class="text-muted" title="Never opened in the chart popup">—</span>
                                </td>
                                <td class="text-end">
                                    <button class="tbl-icon-btn me-1" title="Open charts — weekly + monthly" @click="openChart(h)"><i class="bi bi-bar-chart-line"></i></button>
                                    <a
                                        v-if="h.instrument_token" :href="chartUrl(h)" target="_blank" rel="noopener"
                                        class="tbl-icon-btn me-1" title="Open chart on Kite"
                                    ><i class="bi bi-graph-up"></i></a>
                                    <span v-else class="tbl-icon-btn me-1 disabled" title="Chart unavailable — no Kite instrument token (LTP not found)">
                                        <i class="bi bi-graph-up"></i>
                                    </span>
                                    <button class="tbl-icon-btn" title="Edit" @click="openEditHolding(h)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="tbl-icon-btn tbl-icon-btn-danger ms-1" title="Delete" @click="deleteHolding(h)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Not mapped / Removal -->
            <NotMappedTable
                v-else-if="tab === 'not_mapped'"
                :rows="filteredNotMapped" :buckets="buckets" :loading="loading"
                @changed="fetchData()"
            />
            <RemovalTable
                v-else
                :rows="filteredRemovals" :loading="loading"
                @changed="fetchData()"
            />

        </div>
    </div>

    <BucketForm :show="showBucketForm" :bucket="editBucket" @close="showBucketForm = false" @saved="onBucketSaved" />

    <HoldingForm
        :show="showHoldingForm"
        :holding="editHolding"
        :buckets="buckets"
        :default_bucket_id="typeof tab === 'number' ? tab : ''"
        @close="showHoldingForm = false"
        @saved="fetchData()"
    />

    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" @viewed="onChartViewed" @followed="onChartFollowed" />

    <UploadAside :show="showUpload" @close="showUpload = false" @uploaded="onUploaded" />
</template>
