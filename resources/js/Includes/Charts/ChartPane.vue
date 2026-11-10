<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { echarts, buildOption } from '@/Service/Utils/ChartOptions.js';

const props = defineProps({
    item:      { type: Object, required: true },   // one entry of /api/charts/symbols
    timeframe: { type: String, required: true },   // 'weekly' | 'daily'
    bars:      { type: Number, default: 200 },
    reloadKey: { type: Number, default: 0 },       // bump to refetch (e.g. after a sync)
    compact:   { type: Boolean, default: false },  // popup mode: fills its box, smaller text
    autoStart: { type: Boolean, default: false },  // load straight away instead of waiting to scroll into view
    picking:   { type: Boolean, default: false },  // the Fibonacci editor wants a candle: a click on one is sent up as 'pick'
});

const emit = defineEmits(['sync', 'loaded', 'pick']);

const root     = ref(null);
const chartEl  = ref(null);
const status   = ref('idle');   // idle | loading | ready | empty | error
const hasData  = ref(false);    // a chart has been drawn at least once

const LABELS = { monthly: 'Monthly', weekly: 'Weekly', daily: 'Daily' };

// refetching (filter changed, sync finished): keep the old chart on screen under a loading veil, so the page does not jump
const reloading = computed(() => status.value === 'loading' && hasData.value);

let chart = null;
let data = null;
let observer = null;
let resizer = null;
let started = false;

// Fetch + draw only once the pane has scrolled near the viewport — 100 symbols × 2 charts would otherwise all draw at once
function load() {
    status.value = 'loading';
    DBService.postData('/api/charts/candles', {
        symbol: props.item.symbol, exchange: props.item.exchange, timeframe: props.timeframe, bars: props.bars,
    }).then((res) => {
        if (!res.success) { status.value = 'error'; return; }
        if (!res.candles.length) { status.value = 'empty'; return; }

        data = res;
        hasData.value = true;
        const last = res.candles.length - 1;
        emit('loaded', { timeframe: props.timeframe, close: res.candles[last][1], date: res.x_axis[last] });
        status.value = 'ready';
        nextTick(draw);
    });
}

// While the Fibonacci editor is picking, a click on a candle (its column, price pane or volume pane) reports that candle:
// date, open, close, low, high. A click in the empty stretch on the right (where the Fibonacci lines run) is ignored.
function onChartClick(e) {
    if (!props.picking || !chart || !data) return;
    const p = [e.offsetX, e.offsetY];
    for (const g of [0, 1]) {
        if (!chart.containPixel({ gridIndex: g }, p)) continue;
        const i = Math.round(chart.convertFromPixel({ gridIndex: g }, p)[0]);
        if (!(i >= 0 && i < data.candles.length)) return;
        const c = data.candles[i];
        emit('pick', { timeframe: props.timeframe, date: data.x_axis[i], open: c[0], close: c[1], low: c[2], high: c[3] });
        return;
    }
}

function draw() {
    if (!chartEl.value || !data) return;
    if (!chart) {
        chart = echarts.init(chartEl.value);
        resizer = new ResizeObserver(() => chart && chart.resize());
        resizer.observe(chartEl.value);
        chart.getZr().on('click', onChartClick);
    }
    chart.setOption(buildOption(data, props.item.marks, { compact: props.compact, fibs: props.item.fibs || [] }), true);
}

onMounted(() => {
    // a popup pane is on screen from the start — no need to wait for the scroll observer
    if (props.autoStart) {
        started = true;
        load();
        return;
    }

    observer = new IntersectionObserver((entries) => {
        if (entries.some(e => e.isIntersecting) && !started) {
            started = true;
            load();
        }
    }, { rootMargin: '600px 0px' });
    observer.observe(root.value);
});

// price lines (avg / buy / stop loss) can arrive after the candles — redraw when they do
watch(() => props.item.marks, () => { if (data) draw(); }, { deep: true });

// Fibonacci levels are added / hidden from the editor while the chart is on screen
watch(() => props.item.fibs, () => { if (data) draw(); }, { deep: true });

// an already-drawn chart refetches when the bar count changes or a sync finished
watch(() => [props.bars, props.reloadKey], () => { if (started) load(); });

onBeforeUnmount(() => {
    if (observer) observer.disconnect();
    if (resizer) resizer.disconnect();
    if (chart) chart.dispose();
});
</script>

<template>
    <div ref="root" class="chart-pane" :class="{ 'chart-pane-fit': compact, 'chart-picking': picking }">
        <div class="chart-pane-label"><span class="form-badge">{{ LABELS[timeframe] }}</span></div>

        <div class="chart-wrap">
            <div v-show="status === 'ready' || reloading" ref="chartEl" :class="compact ? 'chart-area-fit' : 'chart-area'"></div>
            <div v-if="reloading" class="chart-loading">
                <span class="spinner-border spinner-border-sm me-2"></span> Loading…
            </div>
        </div>

        <div v-if="status !== 'ready' && !reloading" class="chart-placeholder" :class="{ 'chart-placeholder-fit': compact }">
            <template v-if="status === 'idle' || status === 'loading'">
                <span class="spinner-border spinner-border-sm me-2"></span> Loading…
            </template>
            <template v-else-if="status === 'error'">Could not load this chart</template>
            <template v-else>
                No {{ timeframe }} candle data yet for {{ item.exchange }}:{{ item.symbol }}
                <button class="btn btn-sm btn-outline-primary ms-3" @click="emit('sync', item.symbol)">Fetch history</button>
            </template>
        </div>
    </div>
</template>
