<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import ChartPane from '@/Includes/Charts/ChartPane.vue';
import TradePanel from '@/Includes/Trade/TradePanel.vue';
import FibAside from '@/Includes/Fib/FibAside.vue';
import { fmtPrice } from '@/Service/Utils/Format.js';

// One symbol in a popup, with Previous / Next through the list the page was showing. Nav pills pick what the chart area
// shows: Weekly, Monthly or Daily on their own (the whole area, no scrolling), or "All" — the three side by side.
const props = defineProps({
    show:       { type: Boolean, required: true },
    items:      { type: Array,   default: () => [] },   // [{ exchange, symbol, name? }] — the list to walk through
    startIndex: { type: Number,  default: 0 },
});

const emit = defineEmits(['close', 'viewed', 'followed']);

const VIEWS = [
    { key: 'weekly',  label: 'Weekly',  tfs: ['weekly'],                     hint: '1' },
    { key: 'monthly', label: 'Monthly', tfs: ['monthly'],                    hint: '2' },
    { key: 'daily',   label: 'Daily',   tfs: ['daily'],                      hint: '3' },
    { key: 'all',     label: 'All',     tfs: ['weekly', 'monthly', 'daily'], hint: '4', icon: 'bi-layout-three-columns', title: 'Weekly, monthly and daily side by side' },
];

// how many candles fit: a chart that has the whole width can show more than one of three side by side
const BARS = {
    single: { weekly: 156, monthly: 72, daily: 250 },
    all:    { weekly: 104, monthly: 60, daily: 130 },
};

// The chosen view is remembered, so stepping through a list keeps it. Storage can be blocked, so this is only a convenience.
const VIEW_KEY = 'chart_popup_view';
const view = ref((() => { try { const v = localStorage.getItem(VIEW_KEY); return VIEWS.some(x => x.key === v) ? v : 'weekly'; } catch (e) { return 'weekly'; } })());

function setView(key) {
    view.value = key;
    try { localStorage.setItem(VIEW_KEY, key); } catch (e) { /* not critical */ }
}

const panes = computed(() => {
    const v = VIEWS.find(x => x.key === view.value) || VIEWS[0];
    const bars = BARS[view.value === 'all' ? 'all' : 'single'];
    return v.tfs.map(tf => ({ tf, bars: bars[tf] }));
});

// A symbol only counts as "viewed" once it has stayed on screen this long — tapping Next quickly through a list
// must not mark everything it flashes past (that would defeat the "not viewed today" filter).
const DWELL_MS = 1500;

// The Trade panel (Claude's analysis, your plan, paper trades) sits beside the charts. Whether it is open is remembered, so it
// stays open while you step through a list. Storage can be blocked, so this is only a convenience.
const TRADE_KEY = 'chart_trade_open';
const tradeOn = typeof trade_signals_enabled === 'undefined' ? true : trade_signals_enabled;   // TRADE_SIGNALS_ENABLED in .env
const showTrade = ref(tradeOn && (() => { try { return localStorage.getItem(TRADE_KEY) === '1'; } catch (e) { return false; } })());
function toggleTrade() {
    if (!tradeOn) return;
    showTrade.value = !showTrade.value;
    try { localStorage.setItem(TRADE_KEY, showTrade.value ? '1' : '0'); } catch (e) { /* not critical */ }
}

// The Fibonacci editor (a drawer on top of the popup). While it is open the popup's keyboard shortcuts stand down.
const showFib = ref(false);

const index  = ref(0);
const bodyEl = ref(null);   // the scrolling area — sent back to the top (weekly) on every Next / Previous
const detail = ref(null);   // this symbol's entry from /api/charts/symbols: price lines, followed flag, Kite token
const last   = ref(null);   // last close shown in the header

let dwellTimer = null;

const keyOf   = (it) => `${it.exchange}:${it.symbol}`;
const current = computed(() => props.items[index.value] || null);
const hasPrev = computed(() => index.value > 0);
const hasNext = computed(() => index.value < props.items.length - 1);

// what the panes draw. Marks arrive a moment after the candles; the panes redraw when they do.
const paneItem = computed(() => current.value
    ? { exchange: current.value.exchange, symbol: current.value.symbol, marks: detail.value ? detail.value.marks : [], fibs: detail.value ? (detail.value.fibs || []) : [] }
    : null);

// the full chart screen (weekly + daily, zoomable) for this symbol, in a new tab
const fullChartUrl = computed(() => current.value
    ? `${base_url}/charts?symbol=${encodeURIComponent(current.value.symbol)}&exchange=${current.value.exchange}`
    : null);

const kiteUrl = computed(() => detail.value && detail.value.instrument_token
    ? `https://kite.zerodha.com/markets/ext/chart/web/ciq/${current.value.exchange}/${encodeURIComponent(current.value.symbol)}/${detail.value.instrument_token}`
    : null);

// ─── Showing a symbol ────────────────────────────────────────────────

function enter() {
    clearTimeout(dwellTimer);
    showFib.value = false;
    detail.value = null;   // never show the previous symbol's price lines on this one
    last.value   = null;
    if (bodyEl.value) bodyEl.value.scrollTop = 0;

    const cur = current.value;
    if (!cur) return;
    const key = keyOf(cur);

    DBService.postData('/api/charts/symbols', { source: 'symbol', exchange: cur.exchange, symbol: cur.symbol }).then((data) => {
        // the user may already have moved on while this was loading
        if (data.success && current.value && keyOf(current.value) === key) {
            detail.value = data.symbols[0] || null;
        }
    });

    dwellTimer = setTimeout(() => markViewed(cur), DWELL_MS);
}

function markViewed(cur) {
    DBService.postData('/api/charts/viewed', { symbol: cur.symbol, exchange: cur.exchange }).then((data) => {
        if (data.success) emit('viewed', { exchange: cur.exchange, symbol: cur.symbol, date: data.date });
    });
}

function go(delta) {
    const n = index.value + delta;
    if (n < 0 || n >= props.items.length) return;
    index.value = n;
    enter();
}

watch(() => props.show, (val) => {
    if (val) {
        index.value = Math.min(Math.max(props.startIndex, 0), Math.max(props.items.length - 1, 0));
        enter();
        window.addEventListener('keydown', onKey);
    } else {
        clearTimeout(dwellTimer);
        window.removeEventListener('keydown', onKey);
    }
}, { immediate: true });

// ← / → move through the list, 1–4 pick Weekly / Monthly / Daily / All, F follows / unfollows, T opens the Trade panel, Esc closes (ignored while typing in a field)
function onKey(e) {
    if (showFib.value) return;
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes((e.target.tagName || '').toUpperCase())) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;   // leave browser shortcuts alone

    if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
    else if (e.key === 'Escape') { emit('close'); }
    else if (e.key === 'f' || e.key === 'F') { e.preventDefault(); toggleFollow(); }
    else if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleTrade(); }
    else if (['1', '2', '3', '4'].includes(e.key)) { e.preventDefault(); setView(VIEWS[Number(e.key) - 1].key); }
}

onBeforeUnmount(() => {
    clearTimeout(dwellTimer);
    window.removeEventListener('keydown', onKey);
});

// ─── Header actions ──────────────────────────────────────────────────

// the header shows the last close; the weekly candle is the freshest of the two on screen. (The date shown comes from
// the stored DAILY data — a weekly / monthly candle is dated by its first day, which would look stale.)
function onLoaded(info) {
    if (!last.value || info.timeframe === 'weekly') last.value = info;
}

function toggleFollow() {
    if (!detail.value || !current.value) return;
    const cur    = current.value;
    const follow = detail.value.followed ? 0 : 1;
    detail.value.followed = !!follow;

    DBService.postData('/api/charts/follow', { symbol: cur.symbol, exchange: cur.exchange, follow }).then((data) => {
        if (!data.success) {
            if (detail.value) detail.value.followed = !follow;   // revert
            alert(data.message);
            return;
        }
        emit('followed', { exchange: cur.exchange, symbol: cur.symbol, follow });
    });
}

// the editor changed this symbol's Fibonacci swings: redraw all the panes with the new lines
function onFibChanged(lines) {
    if (detail.value) detail.value.fibs = lines;
}

function onSync(symbol) {
    DBService.postData('/api/charts/sync', { symbol }).then((data) => {
        alert(data.success ? `${data.message} — reopen this chart in a minute.` : data.message);
    });
}
</script>

<template>
    <teleport to="body">
        <div v-if="show" class="chart-modal-overlay" @click.self="emit('close')">
            <div v-if="current" class="chart-modal">

                <div class="chart-modal-head">
                    <div class="d-flex align-items-center gap-1">
                        <button class="tbl-icon-btn" :disabled="!hasPrev" title="Previous (←)" @click="go(-1)"><i class="bi bi-chevron-left"></i></button>
                        <span class="cell-secondary mono text-center" style="min-width:68px;margin:0">{{ index + 1 }} / {{ items.length }}</span>
                        <button class="tbl-icon-btn" :disabled="!hasNext" title="Next (→)" @click="go(1)"><i class="bi bi-chevron-right"></i></button>
                    </div>

                    <!-- follow / unfollow: sets it everywhere this symbol appears (portfolio buckets + watchlists) -->
                    <button
                        class="btn-secondary-sm follow-btn" :class="{ 'is-following': detail && detail.followed }" :disabled="!detail"
                        :title="detail && detail.followed ? 'Following — click to unfollow (F)' : 'Follow this symbol (F)'" @click="toggleFollow"
                    >
                        <i class="bi" :class="detail && detail.followed ? 'bi-star-fill' : 'bi-star'"></i>
                        {{ detail && detail.followed ? 'Following' : 'Follow' }}
                    </button>

                    <span class="cell-primary mono" style="font-size:17px">{{ current.symbol }}</span>
                    <span v-if="current.exchange !== 'NSE'" class="cell-secondary" style="color:#2E6BFF;margin:0">{{ current.exchange }}</span>
                    <span v-if="current.name" class="text-muted" style="font-size:13px">{{ current.name }}</span>

                    <span v-for="(m, i) in (detail ? detail.marks : [])" :key="i" class="chart-mark" :style="{ color: m.color, borderColor: m.color }">
                        {{ m.label }} {{ fmtPrice(m.price) }}
                    </span>

                    <span v-if="last" class="ms-auto cell-secondary" style="margin:0">
                        Last close <strong class="mono">{{ fmtPrice(last.close) }}</strong><template v-if="detail && detail.last_date"> · data to {{ detail.last_date }}</template>
                    </span>

                    <div class="d-flex align-items-center gap-2" :class="{ 'ms-auto': !last, 'ms-2': last }">
                        <button class="btn-secondary-sm" :class="{ 'trade-btn is-on': detail && detail.fibs && detail.fibs.length }" :disabled="!detail" title="Fibonacci levels for this symbol (drawn on all the charts)" @click="showFib = true">
                            <i class="bi bi-distribute-vertical"></i> Fib
                        </button>
                        <button v-if="tradeOn" class="btn-secondary-sm trade-btn" :class="{ 'is-on': showTrade }" title="Claude's analysis, your plan and paper trades (T)" @click="toggleTrade">
                            <i class="bi bi-lightning-charge"></i> Trade
                        </button>
                        <a class="btn-secondary-sm" :href="fullChartUrl" target="_blank" rel="noopener" title="Open the full chart screen (weekly + daily) in a new tab">
                            <i class="bi bi-arrows-fullscreen"></i> Full chart
                        </a>
                        <a v-if="kiteUrl" class="tbl-icon-btn" :href="kiteUrl" target="_blank" rel="noopener" title="Open chart on Kite"><i class="bi bi-graph-up"></i></a>
                        <button class="tbl-icon-btn" title="Close (Esc)" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <!-- which chart(s) to show -->
                <div class="chart-modal-tabs">
                    <div class="chart-pills" role="tablist">
                        <button
                            v-for="v in VIEWS" :key="v.key" class="chart-pill" :class="{ active: view === v.key }" role="tab" :aria-selected="view === v.key"
                            :title="(v.title || v.label + ' chart') + ' (' + v.hint + ')'" @click="setView(v.key)"
                        >
                            <i v-if="v.icon" class="bi me-1" :class="v.icon"></i>{{ v.label }}
                        </button>
                    </div>
                </div>

                <div class="chart-modal-main">
                    <div ref="bodyEl" class="chart-modal-body" :class="view === 'all' ? 'is-all' : 'is-single'">
                        <!-- keyed by symbol and view: every Next / Previous / pill gives fresh panes sized for it -->
                        <ChartPane
                            v-for="p in panes" :key="keyOf(current) + p.tf + view"
                            :item="paneItem" :timeframe="p.tf" :bars="p.bars" compact auto-start
                            @sync="onSync" @loaded="onLoaded"
                        />
                    </div>

                    <!-- keyed by symbol too: moving to the next stock loads that stock's analysis -->
                    <TradePanel v-if="showTrade" :key="keyOf(current)" :item="current" />
                </div>

            </div>
        </div>

        <FibAside :show="show && showFib" :item="current" top @close="showFib = false" @changed="onFibChanged" />
    </teleport>
</template>
