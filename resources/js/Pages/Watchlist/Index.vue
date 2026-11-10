<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtPct, fmtDate, todayString, pnlClass } from '@/Service/Utils/Format.js';
import ListForm from '@/Includes/Watchlist/ListForm.vue';
import ItemForm from '@/Includes/Watchlist/ItemForm.vue';
import BulkAddAside from '@/Includes/Watchlist/BulkAddAside.vue';
import RiskSettings from '@/Includes/Watchlist/RiskSettings.vue';
import IpoUploadAside from '@/Includes/Watchlist/IpoUploadAside.vue';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';
import { decorate } from '@/Service/Utils/Watchlist.js';

const loading    = ref(false);
const watchlists = ref([]);
const items      = ref([]);
const ltpError   = ref(null);

// 'all' | <watchlist id>
const tab    = ref('all');
const search = ref('');

// 'all' | 'followed'
const followFilter = ref('all');

const sortBy  = ref('symbol');
const sortDir = ref('asc');

const todayStr = todayString();

// The IPO watchlist (filled by the IPO job) is read newest listing first
watch(tab, (id) => {
    const list = watchlists.value.find(w => w.id === id);
    if (list && list.name === 'IPO') {
        sortBy.value  = 'listing_date';
        sortDir.value = 'desc';
    }
});

const showListForm = ref(false);
const editList     = ref(null);
const showItemForm = ref(false);
const editItem     = ref(null);
const showBulk     = ref(false);
const showRisk     = ref(false);
const showIpoUpload = ref(false);

// the most you accept losing on one trade (₹) — drives the position sizing columns
const maxLoss = ref(5000);

// ─── Data ────────────────────────────────────────────────────────────

function fetchData(refresh = false) {
    loading.value = true;
    return DBService.postData('/api/watchlist/data', { refresh: refresh ? 1 : 0 }).then((data) => {
        if (data.success) {
            watchlists.value = data.watchlists;
            items.value      = data.items;
            today.value      = data.today;
            maxLoss.value    = data.settings.max_loss_per_trade;
            ltpError.value   = data.ltp_error;

            if (typeof tab.value === 'number' && !watchlists.value.find(w => w.id === tab.value)) {
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

const activeList = computed(() => watchlists.value.find(w => w.id === tab.value) || null);

// the NSE IPO upload belongs to the IPO tab only
const isIpoTab = computed(() => !!activeList.value && activeList.value.name === 'IPO');

function listCount(id) {
    return items.value.filter(i => i.watchlist_id === id).length;
}

// raw rows + everything derived from them (vs buy %, distance to SL, status, position size)
const decorated = computed(() => items.value.map(i => decorate(i, maxLoss.value)));

const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    let list = typeof tab.value === 'number' ? decorated.value.filter(i => i.watchlist_id === tab.value) : decorated.value;
    if (q) list = list.filter(i => i.symbol.toLowerCase().includes(q) || i.watchlist_name.toLowerCase().includes(q) || (i.name || '').toLowerCase().includes(q));
    if (followFilter.value === 'followed') list = list.filter(i => i.follow);
    if (viewFilter.value === 'not_today')  list = list.filter(i => i.last_viewed !== today.value);
    if (viewFilter.value === 'today')      list = list.filter(i => i.last_viewed === today.value);

    const key = sortBy.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    // an LTP of -1 means "no price" — sorts last like a missing value
    const val = (i) => (key === 'ltp' && i.ltp < 0) ? null : i[key];

    return [...list].sort((a, b) => {
        const x = val(a), y = val(b);
        if (x === null && y === null) return 0;
        if (x === null) return 1;
        if (y === null) return -1;
        if (typeof x === 'string') return x.localeCompare(y) * dir;
        return (x - y) * dir;
    });
});

function sort(col) {
    if (sortBy.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value  = col;
        sortDir.value = (col === 'symbol' || col === 'watchlist_name') ? 'asc' : 'desc';   // follow: starred first (desc)
    }
}

function sortIcon(col) {
    if (sortBy.value !== col) return 'bi-chevron-expand';
    return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
}

// ─── Actions ─────────────────────────────────────────────────────────

function openAddList() {
    editList.value = null;
    showListForm.value = true;
}

function openEditList() {
    editList.value = activeList.value;
    showListForm.value = true;
}

function onListSaved(id) {
    fetchData().then(() => { if (id) tab.value = id; });
}

function deleteList() {
    const w = activeList.value;
    if (!w) return;
    const n = listCount(w.id);
    if (n > 0) {
        alert(`"${w.name}" still has ${n} symbol${n === 1 ? '' : 's'}. A watchlist can only be deleted when it is empty.`);
        return;
    }
    if (!confirm(`Delete watchlist "${w.name}"?`)) return;
    DBService.postData('/api/watchlist/list/delete', { id: w.id }).then((data) => {
        if (!data.success) alert(data.message);
        else { tab.value = 'all'; fetchData(); }
    });
}

function openAddItem() {
    editItem.value = null;
    showItemForm.value = true;
}

function openEditItem(i) {
    editItem.value = i;
    showItemForm.value = true;
}

// Star / unstar one symbol (instant; reverted by a refetch if the save fails)
function toggleFollow(i) {
    const follow = i.follow ? 0 : 1;
    i.follow = follow;
    DBService.postData('/api/watchlist/item/follow', { ids: [i.id], follow }).then((data) => {
        if (!data.success) {
            alert(data.message);
            fetchData();
        }
    });
}

// NOTE: the buy price / stop loss / sizing columns were taken out of the table to declutter it; savePrice() and decorate()
// are kept for the coming "Trade" button, which will show this data.
// Inline edit of the buy price / stop loss: saved on change (blur or Enter). Both values go to the server so it can
// check "stop loss below buy price"; on any problem the field snaps back to what is stored.
function savePrice(row, field, event) {
    const raw  = items.value.find(x => x.id === row.id);
    const text = event.target.value.trim();
    const value = text === '' ? null : Number(text);
    const revert = () => { event.target.value = raw[field] ?? ''; };

    if (value !== null && (!Number.isFinite(value) || value < 0)) {
        alert('Enter a valid price');
        revert();
        return;
    }
    if (value === raw[field]) return;   // nothing changed

    const payload = { id: raw.id, buy_price: raw.buy_price, stop_loss: raw.stop_loss, [field]: value };
    if (payload.buy_price !== null && payload.stop_loss !== null && payload.stop_loss >= payload.buy_price) {
        alert('Stop loss must be below the buy price');
        revert();
        return;
    }

    DBService.postData('/api/watchlist/item/prices', payload).then((data) => {
        if (!data.success) {
            alert(data.message);
            revert();
            return;
        }
        raw.buy_price = data.buy_price;   // the derived columns recompute from these straight away
        raw.stop_loss = data.stop_loss;
        event.target.value = raw[field] ?? '';
    });
}

function deleteItem(i) {
    if (!confirm(`Remove ${i.symbol} from ${i.watchlist_name}?`)) return;
    DBService.postData('/api/watchlist/item/delete', { id: i.id }).then((data) => {
        if (!data.success) alert(data.message);
        else fetchData();
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
        const k = `${r.exchange}:${r.symbol}`;
        if (seen.has(k)) return;
        seen.add(k);
        list.push({ exchange: r.exchange, symbol: r.symbol, name: r.name });
    });
    popupItems.value = list;
    popupStart.value = Math.max(0, list.findIndex(x => x.exchange === row.exchange && x.symbol === row.symbol));
    showChartPopup.value = true;
}

// the popup stamped a chart as viewed: update every row of that symbol, which also re-applies the filter behind it
function onChartViewed({ exchange, symbol, date }) {
    items.value.forEach((r) => { if (r.exchange === exchange && r.symbol === symbol) r.last_viewed = date; });
}

function onChartFollowed({ exchange, symbol, follow }) {
    items.value.forEach((r) => { if (r.exchange === exchange && r.symbol === symbol) r.follow = follow; });
}

// ─── Links ───────────────────────────────────────────────────────────

const chartsUrl = computed(() => base_url + '/charts?watchlist=' + (typeof tab.value === 'number' ? tab.value : 'all'));
const symbolChartUrl = (i) => `${base_url}/charts?symbol=${encodeURIComponent(i.symbol)}&exchange=${i.exchange}`;
const kiteChartUrl = (i) => `https://kite.zerodha.com/markets/ext/chart/web/ciq/${i.exchange}/${encodeURIComponent(i.symbol)}/${i.instrument_token}`;
</script>

<template>
    <PageHeader
        title="Watchlist"
        subtitle="Symbols you are tracking — with buy price and stop loss"
        placeholder="Search symbol…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" @click="openAddList">
                <i class="bi bi-folder-plus"></i> New Watchlist
            </button>
            <button class="btn-new-filing" :disabled="!watchlists.length" @click="showBulk = true">
                <i class="bi bi-list-ul me-1"></i> Bulk Add
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="ltpError" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            LTP unavailable — showing -1. {{ ltpError }}
        </div>

        <div class="card-panel">

            <!-- Tabs -->
            <div class="pf-tabs">
                <button class="pf-tab" :class="{ active: tab === 'all' }" @click="tab = 'all'">
                    All <span class="pf-tab-count">{{ items.length }}</span>
                </button>
                <button v-for="w in watchlists" :key="w.id" class="pf-tab" :class="{ active: tab === w.id }" @click="tab = w.id">
                    {{ w.name }} <span class="pf-tab-count">{{ listCount(w.id) }}</span>
                </button>
            </div>

            <!-- Toolbar -->
            <div class="d-flex align-items-center gap-2 m-3">
                <div v-if="activeList" class="d-flex align-items-center gap-2">
                    <span class="fw-semibold">{{ activeList.name }}</span>
                    <button class="tbl-icon-btn" title="Rename watchlist" @click="openEditList"><i class="bi bi-pencil"></i></button>
                    <button
                        class="tbl-icon-btn tbl-icon-btn-danger"
                        :class="{ disabled: listCount(activeList.id) > 0 }"
                        :title="listCount(activeList.id) > 0 ? `Can't delete — it still has ${listCount(activeList.id)} symbol(s)` : 'Delete watchlist'"
                        @click="deleteList"
                    ><i class="bi bi-trash"></i></button>
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button v-if="isIpoTab" class="btn-secondary-sm" title="Add IPOs from NSE's past-issue CSV" @click="showIpoUpload = true">
                        <i class="bi bi-upload"></i> Upload IPO CSV
                    </button>
                    <button class="btn-secondary-sm" title="Position sizing: the most you accept losing on one trade" @click="showRisk = true">
                        <i class="bi bi-sliders"></i> Max loss ₹{{ fmtNum(maxLoss) }}
                    </button>
                    <select v-model="viewFilter" class="form-select form-select-sm" style="width:auto" title="Charts you have (not) opened in the popup today">
                        <option value="all">Viewed or not</option>
                        <option value="not_today">Not viewed today</option>
                        <option value="today">Viewed today</option>
                    </select>
                    <select v-model="followFilter" class="form-select form-select-sm" style="width:auto" title="Show only the symbols you follow">
                        <option value="all">All symbols</option>
                        <option value="followed">Followed only</option>
                    </select>
                    <a class="btn-secondary-sm" :href="chartsUrl" target="_blank" rel="noopener">
                        <i class="bi bi-bar-chart-line"></i> Charts
                    </a>
                    <button class="btn-secondary-sm" :disabled="loading" @click="fetchData(true)">
                        <span v-if="loading" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="bi bi-arrow-clockwise"></i>
                        Refresh LTP
                    </button>
                    <button class="btn-secondary-sm" :disabled="!watchlists.length" @click="openAddItem">
                        <i class="bi bi-plus-lg"></i> Add Symbol
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="th-sort" :class="{ sorted: sortBy === 'follow' }" @click="sort('follow')" style="width:44px" title="Follow">
                                <i class="bi" :class="sortBy === 'follow' ? sortIcon('follow') : 'bi-star'"></i>
                            </th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'symbol' }" @click="sort('symbol')" style="min-width:150px">
                                Symbol <i class="bi" :class="sortIcon('symbol')"></i>
                            </th>
                            <th v-if="tab === 'all'" class="th-sort" :class="{ sorted: sortBy === 'watchlist_name' }" @click="sort('watchlist_name')">
                                Watchlist <i class="bi" :class="sortIcon('watchlist_name')"></i>
                            </th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'listing_date' }" @click="sort('listing_date')" style="min-width:130px" title="IPO listing date">
                                Listing Date <i class="bi" :class="sortIcon('listing_date')"></i>
                            </th>
                            <th class="th-sort text-end" :class="{ sorted: sortBy === 'ltp' }" @click="sort('ltp')">
                                LTP <i class="bi" :class="sortIcon('ltp')"></i>
                            </th>
                            <th>Status</th>
                            <th title="Do you already own this stock in the portfolio?">In Portfolio</th>
                            <th class="th-sort" :class="{ sorted: sortBy === 'last_viewed' }" @click="sort('last_viewed')" style="min-width:110px" title="When you last opened this chart in the popup">
                                Last Viewed <i class="bi" :class="sortIcon('last_viewed')"></i>
                            </th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading && !items.length">
                            <td :colspan="tab === 'all' ? 9 : 8" class="text-center py-5 text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading…
                            </td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td :colspan="tab === 'all' ? 9 : 8" class="text-center py-5 text-muted">
                                {{ search ? 'No symbols match your search' : (watchlists.length ? 'No symbols yet — use Bulk Add or Add Symbol' : 'Create a watchlist to get started') }}
                            </td>
                        </tr>
                        <template v-else>
                            <tr v-for="i in rows" :key="i.id">
                                <td>
                                    <button class="pf-star" :class="{ on: i.follow }" :title="i.follow ? 'Following — click to unfollow' : 'Follow (show in followed charts)'" @click="toggleFollow(i)">
                                        <i class="bi" :class="i.follow ? 'bi-star-fill' : 'bi-star'"></i>
                                    </button>
                                </td>
                                <td>
                                    <span class="cell-primary mono">{{ i.symbol }}</span>
                                    <div v-if="i.name" class="cell-secondary">{{ i.name }}</div>
                                    <div class="cell-secondary mono" :class="{ 'text-primary': i.exchange !== 'NSE' }">{{ i.exchange }}:{{ i.symbol }}</div>
                                </td>
                                <td v-if="tab === 'all'" style="font-size:13px">{{ i.watchlist_name }}</td>
                                <td class="text-nowrap" style="font-size:13px">
                                    <template v-if="i.listing_date">
                                        {{ fmtDate(i.listing_date) }}
                                        <span v-if="i.listing_date > todayStr" class="status-chip chip-amber ms-1" title="Not listed yet">Upcoming</span>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-num">
                                    <span v-if="i.ltp < 0" class="status-chip chip-red" :title="`No LTP for ${i.exchange}:${i.symbol}`">-1</span>
                                    <template v-else>{{ fmtPrice(i.ltp) }}</template>
                                </td>
                                <td>
                                    <span v-if="i.status === 'sl_hit'" class="status-chip chip-red">SL hit</span>
                                    <span v-else-if="i.status === 'buy_zone'" class="status-chip chip-green">Buy zone</span>
                                </td>
                                <td>
                                    <span v-if="i.in_portfolio" class="status-chip chip-green" :title="i.in_portfolio.buckets.join(', ')">
                                        Held · {{ fmtNum(i.in_portfolio.quantity) }}
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-nowrap" style="font-size:12.5px">
                                    <span v-if="i.last_viewed && i.last_viewed === today" class="status-chip chip-green" title="You looked at this chart today">Today</span>
                                    <span v-else-if="i.last_viewed" class="text-muted">{{ fmtDate(i.last_viewed) }}</span>
                                    <span v-else class="text-muted" title="Never opened in the chart popup">—</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="tbl-icon-btn me-1" title="Open charts — weekly + monthly" @click="openChart(i)"><i class="bi bi-bar-chart-line"></i></button>
                                    <a v-if="i.instrument_token" class="tbl-icon-btn me-1" :href="kiteChartUrl(i)" target="_blank" rel="noopener" title="Open chart on Kite">
                                        <i class="bi bi-graph-up"></i>
                                    </a>
                                    <button class="tbl-icon-btn" title="Edit" @click="openEditItem(i)"><i class="bi bi-pencil"></i></button>
                                    <button class="tbl-icon-btn tbl-icon-btn-danger ms-1" title="Remove" @click="deleteItem(i)"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <IpoUploadAside :show="showIpoUpload" :watchlist_id="activeList ? activeList.id : ''" @close="showIpoUpload = false" @uploaded="fetchData()" />

    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" @viewed="onChartViewed" @followed="onChartFollowed" />

    <RiskSettings :show="showRisk" :max-loss="maxLoss" @close="showRisk = false" @saved="(v) => maxLoss = v" />

    <ListForm :show="showListForm" :list="editList" @close="showListForm = false" @saved="onListSaved" />

    <ItemForm
        :show="showItemForm" :item="editItem" :watchlists="watchlists"
        :default_list_id="typeof tab === 'number' ? tab : ''"
        @close="showItemForm = false" @saved="fetchData()"
    />

    <BulkAddAside
        :show="showBulk" :watchlists="watchlists"
        :default_list_id="typeof tab === 'number' ? tab : ''"
        @close="showBulk = false" @saved="fetchData()"
    />
</template>
