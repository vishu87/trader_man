<script setup>
import { ref } from 'vue';
import ChartPane from '@/Includes/Charts/ChartPane.vue';
import { fmtPrice } from '@/Service/Utils/Format.js';

// ONE box per symbol: a single header, then its weekly and daily charts stacked inside.
defineProps({
    item:       { type: Object, required: true },    // one entry of /api/charts/symbols
    timeframes: { type: Array,  required: true },    // ['weekly', 'daily'] | ['weekly'] | ['daily']
    bars:       { type: Number, default: 200 },
    reloadKey:  { type: Number, default: 0 },        // bump to refetch (e.g. after a sync)
});

const emit = defineEmits(['sync', 'follow', 'remove', 'fib']);

const kiteUrl = (i) => `https://kite.zerodha.com/markets/ext/chart/web/ciq/${i.exchange}/${encodeURIComponent(i.symbol)}/${i.instrument_token}`;

// header shows the last close; prefer the daily chart's (fresher) over the weekly one
const last = ref(null);
function onLoaded(info) {
    if (!last.value || info.timeframe === 'daily') last.value = info;
}
</script>

<template>
    <div class="card-panel chart-card">
        <div class="chart-card-head">
            <button class="pf-star" :class="{ on: item.followed }" :title="item.followed ? 'Following — click to unfollow' : 'Follow this symbol'" @click="emit('follow', item)">
                <i class="bi" :class="item.followed ? 'bi-star-fill' : 'bi-star'"></i>
            </button>
            <span class="cell-primary mono">{{ item.symbol }}</span>
            <span v-if="item.exchange !== 'NSE'" class="cell-secondary" style="color:#2E6BFF">{{ item.exchange }}</span>
            <span v-for="(m, i) in item.marks" :key="i" class="chart-mark" :style="{ color: m.color, borderColor: m.color }">
                {{ m.label }} {{ fmtPrice(m.price) }}
            </span>

            <span v-if="last" class="ms-auto cell-secondary">
                Last close <strong class="mono">{{ fmtPrice(last.close) }}</strong> · {{ last.date }}
            </span>

            <div class="d-flex align-items-center gap-1" :class="{ 'ms-auto': !last, 'ms-2': last }">
                <!-- open this symbol's chart on Kite (needs the instrument token captured with the LTP) -->
                <a v-if="item.instrument_token" class="tbl-icon-btn" :href="kiteUrl(item)" target="_blank" rel="noopener" title="Open chart on Kite">
                    <i class="bi bi-graph-up"></i>
                </a>
                <span v-else class="tbl-icon-btn disabled" title="Kite link unavailable — no instrument token yet (it is captured with the LTP)">
                    <i class="bi bi-graph-up"></i>
                </span>

                <!-- Fibonacci levels: drawn on every chart of this symbol -->
                <button class="tbl-icon-btn" :class="{ 'fib-on': item.fibs && item.fibs.length }" :title="item.fibs && item.fibs.length ? 'Fibonacci levels (shown) — edit' : 'Add Fibonacci levels'" @click="emit('fib', item)">
                    <i class="bi bi-distribute-vertical"></i>
                </button>

                <!-- refetch just this symbol's candles (a symbol-specific sync never skips) -->
                <button class="tbl-icon-btn" title="Refetch candles for this symbol" @click="emit('sync', item.symbol)">
                    <i class="bi bi-arrow-repeat"></i>
                </button>

                <!-- only for symbols that are in a watchlist and NOT in the portfolio -->
                <button
                    v-if="item.can_remove" class="tbl-icon-btn tbl-icon-btn-danger"
                    title="Remove from watchlist (it is not in your portfolio)" @click="emit('remove', item)"
                ><i class="bi bi-trash"></i></button>
            </div>
        </div>

        <ChartPane
            v-for="tf in timeframes" :key="tf"
            :item="item" :timeframe="tf" :bars="bars" :reload-key="reloadKey"
            @sync="(s) => emit('sync', s)" @loaded="onLoaded"
        />
    </div>
</template>
