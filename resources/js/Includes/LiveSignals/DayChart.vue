<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { echarts } from '@/Service/Utils/ChartOptions.js';
import { fmtPrice, fmtNum, fmtDate } from '@/Service/Utils/Format.js';

// One stock's day on 5-minute candles, with what live trading did on it: the alert(s), our buys and sells, and the
// average / stop / target lines. The "20 SMA" is the level the 2nd buy waits for (the average of the 20 closes before each candle).
const props = defineProps({
    show:   { type: Boolean, required: true },
    symbol: { type: String,  default: '' },
    date:   { type: String,  default: '' },   // Y-m-d to open on
    endpoint: { type: String, default: '/api/live/chart' },   // where the candles come from
    // optional: what to draw on the candles, supplied by the page (the Scanner draws its backtest trades). Without it the server's
    // alerts / fills / lines are used (Live Signals). { alerts: [{date, t, price, alert, status, reason}], fills: [{date, t, price, qty, side, kind}], lines: [{label, price, color}] }
    marks:  { type: Object, default: null },
});
const emit = defineEmits(['close']);

const day     = ref('');
const loading = ref(false);
const error   = ref('');
const data    = ref(null);
const chartEl = ref(null);

let chart = null;
let ro = null;
let seq = 0;   // a slow answer for an earlier day must not overwrite a newer one

const GREEN = '#0E9F5B', RED = '#E03131', BLUE = '#2E6BFF', AMBER = '#F59F00', GREY = '#868E96';

function load() {
    if (!props.symbol) return;
    const mine = ++seq;
    loading.value = true;
    error.value = '';
    DBService.postData(props.endpoint, { symbol: props.symbol, date: day.value }).then((res) => {
        if (mine !== seq) return;
        loading.value = false;
        if (!res.success) { data.value = null; error.value = res.message || 'Could not load the chart'; return; }
        data.value = res;
        day.value = res.date;
        nextTick(draw);
    }).catch(() => { if (mine === seq) { loading.value = false; data.value = null; error.value = 'Could not load the chart'; } });
}

watch(() => props.show, (v) => {
    if (v) { day.value = props.date || ''; data.value = null; load(); }
    else { seq++; if (chart) { chart.dispose(); chart = null; } if (ro) { ro.disconnect(); ro = null; } }
});

// step to the previous / next weekday (a weekend has no candles)
function step(dir) {
    const d = new Date((day.value || props.date) + 'T00:00:00');
    do { d.setDate(d.getDate() + dir); } while (d.getDay() === 0 || d.getDay() === 6);
    day.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    load();
}

// which candle a time (HH:MM) belongs to
function indexOf(t) {
    const cs = data.value.candles;
    const m = parseInt(t.slice(0, 2), 10) * 60 + parseInt(t.slice(3, 5), 10);
    let idx = 0;
    for (let i = 0; i < cs.length; i++) {
        const cm = parseInt(cs[i].t.slice(0, 2), 10) * 60 + parseInt(cs[i].t.slice(3, 5), 10);
        if (cm <= m) idx = i; else break;
    }
    return idx;
}

// what is drawn on the day shown
const shown = computed(() => {
    if (!data.value) return { alerts: [], fills: [], lines: [] };
    if (!props.marks) return { alerts: data.value.alerts, fills: data.value.fills, lines: data.value.lines };
    return {
        alerts: props.marks.alerts.filter(a => a.date === data.value.date),
        fills:  props.marks.fills.filter(f => f.date === data.value.date),
        lines:  props.marks.lines || [],
    };
});

const events = computed(() => {
    if (!data.value) return [];
    const out = [];
    const rsiTxt = (t) => { const r = data.value.candles[indexOf(t)].rsi; return r === null || r === undefined ? '' : 'RSI ' + fmtNum(r, 1); };
    for (const a of shown.value.alerts) out.push({ t: a.t, kind: 'alert', text: a.alert + ' alert' + (a.price ? ' at ₹' + fmtPrice(a.price) : ''), rsi: rsiTxt(a.t), note: a.status === 'done' ? 'acted on' : (a.reason || a.status) });
    for (const f of shown.value.fills) out.push({ t: f.t, kind: f.side, text: f.qty ? (f.side === 'buy' ? 'Bought ' : 'Sold ') + fmtNum(f.qty) + ' @ ₹' + fmtPrice(f.price) : (f.side === 'buy' ? 'Entry at ₹' : 'Exit at ₹') + fmtPrice(f.price), rsi: rsiTxt(f.t), note: f.kind.replace('_', ' ') + (f.mode === 'dry_run' ? ' · dry run' : '') });
    return out.sort((a, b) => a.t.localeCompare(b.t));
});

function draw() {
    if (!chartEl.value || !data.value) return;
    if (!chart) {
        chart = echarts.init(chartEl.value);
        ro = new ResizeObserver(() => chart && chart.resize());
        ro.observe(chartEl.value);
    }
    const d = data.value, cs = d.candles;
    const n = cs.length;

    const lows = cs.map(c => c.l), highs = cs.map(c => c.h);
    const { alerts, fills, lines } = shown.value;
    const extra = lines.map(l => l.price).concat(d.prev_close ? [d.prev_close] : []);
    const min = Math.min(...lows, ...extra), max = Math.max(...highs, ...extra);
    const pad = (max - min) * 0.04 || 1;

    // markers sit on their candle; several in one candle keep the last
    const alertAt = {}, buyAt = {}, sellAt = {};
    alerts.forEach(a => { alertAt[indexOf(a.t)] = a; });
    fills.forEach(f => { (f.side === 'buy' ? buyAt : sellAt)[indexOf(f.t)] = f; });
    const series = (map, price) => cs.map((c, i) => map[i] ? price(map[i], c) : null);

    const markLines = lines.map(l => ({ yAxis: l.price, name: l.label, lineStyle: { color: l.color, type: 'dashed', width: 1.2 }, label: { formatter: l.label + ' ₹' + fmtPrice(l.price), color: l.color, fontSize: 10, position: 'insideEndTop' } }));
    if (d.prev_close) markLines.push({ yAxis: d.prev_close, name: 'previous close', lineStyle: { color: GREY, type: 'dotted', width: 1 }, label: { formatter: 'prev close ₹' + fmtPrice(d.prev_close), color: GREY, fontSize: 10, position: 'insideEndTop' } });

    const marker = (name, map, price, symbol, color, rotate) => ({
        name, type: 'line', xAxisIndex: 0, yAxisIndex: 0, data: series(map, price), showSymbol: true, showAllSymbol: true, symbol, symbolSize: 14, symbolRotate: rotate || 0,
        lineStyle: { opacity: 0 }, itemStyle: { color, borderColor: '#fff', borderWidth: 1 }, z: 10, silent: true, connectNulls: false,
    });

    const rsiMarker = (map, symbol, color, rotate) => ({
        type: 'line', xAxisIndex: 1, yAxisIndex: 1, data: cs.map((c, i) => map[i] && c.rsi !== null && c.rsi !== undefined ? c.rsi : null), showSymbol: true, showAllSymbol: true,
        symbol, symbolSize: 10, symbolRotate: rotate || 0, lineStyle: { opacity: 0 }, itemStyle: { color, borderColor: '#fff', borderWidth: 1 }, z: 10, silent: true, tooltip: { show: false },
    });

    chart.setOption({
        animation: false,
        legend: { top: 0, textStyle: { fontSize: 11 }, data: ['20 SMA', 'VWAP', 'RSI 14', 'Alert', 'Buy', 'Sell'] },
        tooltip: {
            trigger: 'axis', axisPointer: { type: 'cross' },
            formatter: (ps) => {
                const i = ps[0].dataIndex, c = cs[i];
                let h = `<b>${c.t}</b> &nbsp; O ${fmtPrice(c.o)} · H ${fmtPrice(c.h)} · L ${fmtPrice(c.l)} · C ${fmtPrice(c.c)}<br>Volume ${fmtNum(c.v)}` + (c.sma ? `<br>20 SMA ${fmtPrice(c.sma)}` : '') + (c.vwap ? `<br>VWAP ${fmtPrice(c.vwap)}` : '') + (c.rsi !== null && c.rsi !== undefined ? `<br>RSI ${fmtNum(c.rsi, 1)}` : '');
                if (alertAt[i]) h += `<br><span style="color:${BLUE}">◆ ${alertAt[i].alert} alert${alertAt[i].price ? ' at ₹' + fmtPrice(alertAt[i].price) : ''}</span>`;
                if (buyAt[i]) h += `<br><span style="color:${GREEN}">▲ ${buyAt[i].qty ? 'bought ' + fmtNum(buyAt[i].qty) + ' @' : 'entry'} ₹${fmtPrice(buyAt[i].price)} (${buyAt[i].kind})</span>`;
                if (sellAt[i]) h += `<br><span style="color:${RED}">▼ sold ${fmtNum(sellAt[i].qty)} @ ₹${fmtPrice(sellAt[i].price)} (${sellAt[i].kind.replace('_', ' ')})</span>`;
                return h;
            },
        },
        axisPointer: { link: [{ xAxisIndex: 'all' }] },
        grid: [
            { left: 60, right: 96, top: 34, height: '47%' },        // price
            { left: 60, right: 96, top: '62%', height: '14%' },     // RSI
            { left: 60, right: 96, top: '80%', height: '9%' },      // volume
        ],
        xAxis: [
            { type: 'category', data: cs.map(c => c.t), gridIndex: 0, axisLabel: { show: false }, axisTick: { show: false }, boundaryGap: true },
            { type: 'category', data: cs.map(c => c.t), gridIndex: 1, axisLabel: { show: false }, axisTick: { show: false }, boundaryGap: true },
            { type: 'category', data: cs.map(c => c.t), gridIndex: 2, axisLabel: { fontSize: 10, interval: 5 }, boundaryGap: true },
        ],
        yAxis: [
            { type: 'value', gridIndex: 0, min: +(min - pad).toFixed(2), max: +(max + pad).toFixed(2), scale: true, axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: '#EEF0F4' } } },
            { type: 'value', gridIndex: 1, min: 0, max: 100, interval: 10, axisLabel: { fontSize: 10, formatter: (v) => [30, 50, 70].includes(v) ? v : '' }, splitLine: { show: false } },
            { type: 'value', gridIndex: 2, axisLabel: { show: false }, splitLine: { show: false } },
        ],
        series: [
            {
                name: 'Price', type: 'candlestick', xAxisIndex: 0, yAxisIndex: 0, data: cs.map(c => [c.o, c.c, c.l, c.h]),
                itemStyle: { color: GREEN, color0: RED, borderColor: GREEN, borderColor0: RED },
                markLine: { symbol: 'none', silent: true, animation: false, data: markLines },
            },
            { name: '20 SMA', type: 'line', xAxisIndex: 0, yAxisIndex: 0, data: cs.map(c => c.sma), showSymbol: false, smooth: false, lineStyle: { color: AMBER, width: 1.5 }, itemStyle: { color: AMBER } },
            { name: 'VWAP', type: 'line', xAxisIndex: 0, yAxisIndex: 0, data: cs.map(c => c.vwap), showSymbol: false, smooth: false, lineStyle: { color: '#0CA5B0', width: 1.5, type: 'solid' }, itemStyle: { color: '#0CA5B0' } },
            marker('Alert', alertAt, (a, c) => a.price || c.c, 'diamond', BLUE),
            marker('Buy',   buyAt,   (f) => f.price, 'triangle', GREEN),
            marker('Sell',  sellAt,  (f) => f.price, 'triangle', RED, 180),
            {
                name: 'RSI 14', type: 'line', xAxisIndex: 1, yAxisIndex: 1, data: cs.map(c => c.rsi), showSymbol: false, smooth: false, lineStyle: { color: '#7048E8', width: 1.5 }, itemStyle: { color: '#7048E8' },
                markLine: { symbol: 'none', silent: true, animation: false, label: { show: false }, data: [
                    { yAxis: 70, lineStyle: { color: RED, type: 'dashed', width: 1, opacity: 0.55 } },
                    { yAxis: 50, lineStyle: { color: GREY, type: 'dotted', width: 1, opacity: 0.5 } },
                    { yAxis: 30, lineStyle: { color: GREEN, type: 'dashed', width: 1, opacity: 0.55 } },
                ] },
            },
            // the same alert / buy / sell marks on the RSI, so the RSI at each can be read off
            rsiMarker(alertAt, 'diamond', BLUE),
            rsiMarker(buyAt, 'triangle', GREEN),
            rsiMarker(sellAt, 'triangle', RED, 180),
            { name: 'Volume', type: 'bar', xAxisIndex: 2, yAxisIndex: 2, data: cs.map(c => ({ value: c.v, itemStyle: { color: c.c >= c.o ? '#9BD8B5' : '#F2A5A5' } })) },
        ],
    }, true);
}

onBeforeUnmount(() => { if (ro) ro.disconnect(); if (chart) chart.dispose(); });
</script>

<template>
    <Aside :show="show" :title="symbol + ' — the day'" :subtitle="data ? fmtDate(data.date) + ' · 5-minute candles · ' + data.source : '5-minute candles'" size="xl" @close="emit('close')">
        <div class="dc-bar">
            <button class="btn-secondary-sm" :disabled="loading" @click="step(-1)" title="Previous trading day"><i class="bi bi-chevron-left"></i></button>
            <input v-model="day" type="date" class="form-control form-control-sm" style="width:auto" @change="load" />
            <button class="btn-secondary-sm" :disabled="loading" @click="step(1)" title="Next trading day"><i class="bi bi-chevron-right"></i></button>
            <span v-if="loading" class="spinner-border spinner-border-sm ms-2"></span>
        </div>

        <div v-if="error" class="pf-alert pf-alert-bad zt-result"><i class="bi bi-x-circle-fill"></i><div class="flex-grow-1">{{ error }}</div></div>
        <div v-show="data" ref="chartEl" class="dc-chart"></div>

        <template v-if="data">
            <div class="sc-sub" style="padding:14px 0 6px">What happened this day</div>
            <div v-if="!events.length" class="text-muted" style="font-size:13px">No alert or trade for {{ symbol }} on this day.</div>
            <table v-else class="table table-sm align-middle mb-0 dc-events">
                <tbody>
                    <tr v-for="(e, i) in events" :key="i">
                        <td class="mono text-nowrap" style="width:70px">{{ e.t }}</td>
                        <td class="text-nowrap"><span :style="{ color: e.kind === 'alert' ? '#2E6BFF' : e.kind === 'buy' ? '#0E9F5B' : '#E03131' }">{{ e.kind === 'alert' ? '◆' : e.kind === 'buy' ? '▲' : '▼' }}</span> {{ e.text }}</td>
                        <td class="mono text-muted text-nowrap">{{ e.rsi }}</td>
                        <td class="text-muted">{{ e.note }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </Aside>
</template>
