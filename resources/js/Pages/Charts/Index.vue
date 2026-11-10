<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import ChartCard from '@/Includes/Charts/ChartCard.vue';
import FibAside from '@/Includes/Fib/FibAside.vue';

// initial static data from the controller: what the URL asked for + the lists to pick from
const props = defineProps(['initial', 'buckets', 'watchlists']);

// what is being charted, as one string:  bucket:all | bucket:<id> | watchlist:all | watchlist:<id> | symbol:<EXCHANGE>:<SYMBOL>
function initialSource() {
    const i = props.initial || {};
    if (i.symbol)    return `symbol:${i.exchange || 'NSE'}:${String(i.symbol).toUpperCase()}`;
    if (i.watchlist) return `watchlist:${i.watchlist}`;
    if (i.bucket)    return `bucket:${i.bucket}`;
    return 'bucket:all';
}

const source    = ref(initialSource());

// 'followed' (default) = only symbols you have starred in Portfolio / Watchlist, 'all' = everything.
// The last choice is remembered in this browser; storage can be blocked, so it is only a convenience.
const MODE_KEY = 'charts_view';
function initialMode() {
    try { return localStorage.getItem(MODE_KEY) === 'all' ? 'all' : 'followed'; } catch (e) { return 'followed'; }
}
const viewMode = ref(initialMode());
const totalCount = ref(0);   // symbols in the source before the followed filter
// which charts go inside each symbol's box (largest timeframe first)
const TIMEFRAME_SETS = {
    'weekly+daily':   ['weekly', 'daily'],
    'monthly+weekly': ['monthly', 'weekly'],
    'monthly':        ['monthly'],
    'weekly':         ['weekly'],
    'daily':          ['daily'],
};
const timeframe = ref('weekly+daily');
const bars      = ref(200);
const search    = ref('');

const symbols   = ref([]);
const loading   = ref(false);
const reloadKey = ref(0);

const syncing   = ref(false);
const syncLog   = ref('');
let pollTimer   = null;

// ─── Symbols ─────────────────────────────────────────────────────────

// clear = true when the user changed what is being shown: the old list is dropped at once and a loading state
// shows until the new one arrives (instead of the previous charts lingering as if nothing happened)
function fetchSymbols(clear = false) {
    const [kind, a, b] = source.value.split(':');
    const payload = kind === 'symbol'
        ? { source: 'symbol', exchange: a, symbol: b }
        : { source: kind, id: a, follow_only: viewMode.value === 'followed' };

    if (clear) symbols.value = [];
    loading.value = true;
    return DBService.postData('/api/charts/symbols', payload).then((data) => {
        loading.value = false;
        if (!data.success) { alert(data.message); return; }
        symbols.value = data.symbols;
        totalCount.value = data.total;
        applySyncState(data.sync);
    });
}

function onModeChange() {
    try { localStorage.setItem(MODE_KEY, viewMode.value); } catch (e) { /* not critical */ }
    fetchSymbols(true);
}

function onSourceChange() {
    // keep the address bar shareable / refresh-safe
    const [kind, a, b] = source.value.split(':');
    const qs = kind === 'symbol' ? `?symbol=${encodeURIComponent(b)}&exchange=${a}` : `?${kind}=${a}`;
    history.replaceState(null, '', base_url + '/charts' + qs);
    fetchSymbols(true);
}

onMounted(() => {
    fetchSymbols();
    // pick up a sync that is already running (e.g. started from another tab)
    DBService.postData('/api/charts/sync-status', {}).then(d => d.success && applySyncState(d));
});

onBeforeUnmount(() => clearInterval(pollTimer));

// ─── Filtering ───────────────────────────────────────────────────────

const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    return symbols.value.filter(s => !q || s.symbol.toLowerCase().includes(q));
});

const timeframes = computed(() => TIMEFRAME_SETS[timeframe.value]);

const isSingle = computed(() => source.value.startsWith('symbol:'));
const missingCount = computed(() => symbols.value.filter(s => !s.has_data).length);

const sourceLabel = computed(() => {
    const [kind, a, b] = source.value.split(':');
    if (kind === 'symbol') return `${a}:${b}`;
    if (kind === 'bucket') return a === 'all' ? 'All portfolio buckets' : (props.buckets.find(x => String(x.id) === a) || {}).name;
    return a === 'all' ? 'All watchlists' : (props.watchlists.find(x => String(x.id) === a) || {}).name;
});

// ─── Candle sync ─────────────────────────────────────────────────────

function applySyncState(s) {
    if (!s) return;
    syncLog.value = s.log || '';
    const was = syncing.value;
    syncing.value = !!s.running;

    if (syncing.value && !pollTimer) {
        pollTimer = setInterval(() => DBService.postData('/api/charts/sync-status', {}).then(d => d.success && applySyncState(d)), 4000);
    }
    if (!syncing.value && pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    // just finished → pull the fresh candles into every chart
    if (was && !syncing.value) {
        fetchSymbols().then(() => { reloadKey.value++; });
    }
}

// Star / unstar from a chart. `item` is shared by that symbol's weekly and daily cards, so both stars update.
// In "Followed only" mode an unfollowed symbol stays on screen until the next load, so charts never vanish under you.
function toggleFollow(item) {
    const follow = item.followed ? 0 : 1;
    item.followed = !!follow;
    DBService.postData('/api/charts/follow', { symbol: item.symbol, exchange: item.exchange, follow }).then((data) => {
        if (!data.success) {
            item.followed = !follow;   // revert
            alert(data.message);
        }
    });
}

// Delete a symbol that is only in watchlists (the box only shows the button for those; the server checks again)
function removeSymbol(item) {
    const where = item.watchlists.length ? ` from watchlist${item.watchlists.length === 1 ? '' : 's'} ${item.watchlists.join(', ')}` : '';
    if (!confirm(`Remove ${item.exchange}:${item.symbol}${where}?\n\nIt is not in your portfolio. Its stored candle history is kept.`)) return;

    DBService.postData('/api/charts/remove', { symbol: item.symbol, exchange: item.exchange }).then((data) => {
        if (!data.success) {
            alert(data.message);
            return;
        }
        symbols.value = symbols.value.filter(s => s.key !== item.key);
        totalCount.value = Math.max(0, totalCount.value - 1);
    });
}

// Fibonacci editor for one symbol; its swings are drawn on that symbol's weekly / daily / monthly charts
const fibItem = ref(null);
function onFibChanged(lines) {
    if (fibItem.value) fibItem.value.fibs = lines;   // the item is shared by the symbol's charts, so they all redraw
}

// Remove stored candles of symbols that are in no portfolio bucket, watchlist or paper trade. Asks the server what it would
// remove first, shows that, and only deletes after a yes.
const clearing = ref(false);
function clearUnused() {
    clearing.value = true;
    DBService.postData('/api/charts/clear-unused', {}).then((preview) => {
        if (!preview.success) { clearing.value = false; alert(preview.message); return; }
        if (!preview.symbols) { clearing.value = false; alert('Nothing to clear — every symbol with stored candles is in your portfolio, a watchlist or a trade.'); return; }

        const names = preview.list.join(', ') + (preview.symbols > preview.list.length ? ` … and ${preview.symbols - preview.list.length} more` : '');
        if (!confirm(`Delete the stored candles of ${preview.symbols} symbol${preview.symbols === 1 ? '' : 's'} (${preview.rows.toLocaleString('en-IN')} rows)?\n\n${names}\n\nThey are in no portfolio bucket, watchlist or trade. Adding one back later means fetching its history again.`)) {
            clearing.value = false;
            return;
        }

        DBService.postData('/api/charts/clear-unused', { confirm: 1 }).then((done) => {
            clearing.value = false;
            alert(done.message);
            if (done.success) fetchSymbols();
        });
    });
}

function startSync(symbol = null) {
    DBService.postData('/api/charts/sync', symbol ? { symbol } : {}).then((data) => {
        if (!data.success) { alert(data.message); return; }
        syncing.value = true;
        applySyncState({ running: true, log: 'Starting…' });
    });
}
</script>

<template>
    <PageHeader
        title="Charts"
        subtitle="Weekly and daily candles for every symbol — just scroll"
        placeholder="Filter symbols…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="syncing || clearing" title="Delete stored candles of symbols that are not in the portfolio, any watchlist or any trade" @click="clearUnused()">
                <span v-if="clearing" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-trash3"></i>
                Clear unused candles
            </button>
            <button class="btn-secondary-sm" :disabled="syncing" title="Fetch candles for symbols that are not up to date. Symbols already synced today are skipped — use the ↻ button in a chart to refetch one." @click="startSync()">
                <span v-if="syncing" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-cloud-download"></i>
                {{ syncing ? 'Syncing…' : 'Sync candles' }}
            </button>
        </template>
    </PageHeader>

    <Content>

        <div v-if="syncing" class="pf-alert">
            <i class="bi bi-arrow-repeat"></i>
            Fetching candles from Kite — charts refresh automatically when it finishes.
            <span class="ms-2 mono" style="font-weight:400;white-space:pre-line">{{ syncLog.split('\n').slice(-1)[0] }}</span>
        </div>
        <div v-else-if="missingCount" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ missingCount }} symbol{{ missingCount === 1 ? '' : 's' }} have no candle data yet — use “Sync candles”.
        </div>

        <!-- Controls -->
        <div class="card-panel p-3 mb-3 d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted" style="font-size:12.5px">Show</span>
                <select v-model="source" class="form-select form-select-sm" style="width:auto;min-width:200px" @change="onSourceChange">
                    <optgroup label="Portfolio">
                        <option value="bucket:all">All buckets</option>
                        <option v-for="b in buckets" :key="'b' + b.id" :value="`bucket:${b.id}`">{{ b.name }}</option>
                    </optgroup>
                    <optgroup label="Watchlists">
                        <option value="watchlist:all">All watchlists</option>
                        <option v-for="w in watchlists" :key="'w' + w.id" :value="`watchlist:${w.id}`">{{ w.name }}</option>
                    </optgroup>
                    <optgroup v-if="source.startsWith('symbol:')" label="Single symbol">
                        <option :value="source">{{ sourceLabel }}</option>
                    </optgroup>
                </select>
            </div>

            <div v-if="!isSingle" class="d-flex align-items-center gap-2">
                <span class="text-muted" style="font-size:12.5px">Charts for</span>
                <select v-model="viewMode" class="form-select form-select-sm" style="width:auto" @change="onModeChange">
                    <option value="followed">Followed only ★</option>
                    <option value="all">All symbols</option>
                </select>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="text-muted" style="font-size:12.5px">Timeframe</span>
                <select v-model="timeframe" class="form-select form-select-sm" style="width:auto">
                    <option value="weekly+daily">Weekly + Daily</option>
                    <option value="monthly+weekly">Monthly + Weekly</option>
                    <option value="monthly">Monthly only</option>
                    <option value="weekly">Weekly only</option>
                    <option value="daily">Daily only</option>
                </select>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="text-muted" style="font-size:12.5px">Candles</span>
                <select v-model.number="bars" class="form-select form-select-sm" style="width:auto">
                    <option :value="100">100</option>
                    <option :value="200">200</option>
                    <option :value="300">300</option>
                    <option :value="500">500</option>
                </select>
            </div>

            <span class="ms-auto text-muted" style="font-size:12.5px">
                {{ shown.length }} symbol{{ shown.length === 1 ? '' : 's' }}<template v-if="!isSingle && viewMode === 'followed'"> followed (of {{ totalCount }})</template> · Ctrl + scroll to zoom, drag to pan
            </span>
        </div>

        <div v-if="loading && !symbols.length" class="text-center py-5 text-muted">
            <span class="spinner-border spinner-border-sm me-2"></span> Loading charts…
        </div>
        <div v-else-if="!shown.length" class="card-panel text-center py-5 text-muted">
            <template v-if="search">No symbols match your filter</template>
            <template v-else-if="!isSingle && viewMode === 'followed' && totalCount > 0">
                None of the {{ totalCount }} symbols here are followed yet — click the ★ in Portfolio / Watchlist, or switch to “All symbols”.
            </template>
            <template v-else>Nothing to chart here yet</template>
        </div>

        <!-- One box per symbol, its weekly and daily charts stacked inside (weekly first, like the futures graph page) -->
        <ChartCard
            v-for="s in shown" :key="s.key"
            :item="s" :timeframes="timeframes" :bars="bars" :reload-key="reloadKey"
            @sync="startSync" @follow="toggleFollow" @remove="removeSymbol" @fib="(i) => fibItem = i"
        />

        <FibAside :show="!!fibItem" :item="fibItem" @close="fibItem = null" @changed="onFibChanged" />

    </Content>
</template>
