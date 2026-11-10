<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { echarts } from '@/Service/Utils/ChartOptions.js';

// Daily profit / loss as bars and the running total as a line. With several `lines` (the Compare tab) it draws one running-total line each.
const props = defineProps({
    days:  { type: Array, default: () => [] },     // [{ date, net, cumulative }]  → bars + one line
    lines: { type: Array, default: () => [] },     // [{ name, days: [{ date, cumulative }] }]  → lines only
    height: { type: Number, default: 280 },
});

const el = ref(null);
let chart = null;
let ro = null;

const GREEN = '#0E9F5B', RED = '#E03131', BLUE = '#2E6BFF';
const PALETTE = ['#2E6BFF', '#0E9F5B', '#F59F00', '#E03131', '#7048E8', '#0CA678', '#D6336C', '#495057'];
const inr = (v) => (v < 0 ? '−' : '') + '₹' + Math.abs(Math.round(v)).toLocaleString('en-IN');

function build() {
    if (props.lines.length) {
        const dates = [...new Set(props.lines.flatMap(l => l.days.map(d => d.date)))].sort();
        return {
            animation: false,
            color: PALETTE,
            tooltip: { trigger: 'axis', valueFormatter: inr },
            legend: { type: 'scroll', top: 0, textStyle: { fontSize: 11 } },
            grid: { left: 64, right: 16, top: 40, bottom: 28 },
            xAxis: { type: 'category', data: dates, axisLabel: { fontSize: 10 } },
            yAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => inr(v) }, splitLine: { lineStyle: { color: '#EEF0F4' } } },
            series: props.lines.map(l => {
                const m = Object.fromEntries(l.days.map(d => [d.date, d.cumulative]));
                let last = 0;   // a quiet day keeps the previous total
                return { name: l.name.length > 38 ? l.name.slice(0, 36) + '…' : l.name, type: 'line', showSymbol: false, smooth: false, data: dates.map(d => { if (m[d] !== undefined) last = m[d]; return last; }) };
            }),
        };
    }

    const dates = props.days.map(d => d.date);
    return {
        animation: false,
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: inr },
        legend: { top: 0, textStyle: { fontSize: 11 }, data: ['Day P&L', 'Running total'] },
        grid: { left: 64, right: 64, top: 36, bottom: 28 },
        xAxis: { type: 'category', data: dates, axisLabel: { fontSize: 10 } },
        yAxis: [
            { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => inr(v) }, splitLine: { lineStyle: { color: '#EEF0F4' } } },
            { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => inr(v) }, splitLine: { show: false } },
        ],
        series: [
            { name: 'Day P&L', type: 'bar', data: props.days.map(d => ({ value: d.net, itemStyle: { color: d.net >= 0 ? GREEN : RED } })) },
            { name: 'Running total', type: 'line', yAxisIndex: 1, showSymbol: false, lineStyle: { color: BLUE, width: 2 }, itemStyle: { color: BLUE }, data: props.days.map(d => d.cumulative) },
        ],
    };
}

function draw() {
    if (!el.value) return;
    if (!chart) chart = echarts.init(el.value);
    chart.setOption(build(), true);
}

onMounted(() => {
    draw();
    ro = new ResizeObserver(() => chart && chart.resize());
    ro.observe(el.value);
});
onBeforeUnmount(() => { if (ro) ro.disconnect(); if (chart) chart.dispose(); });
watch(() => [props.days, props.lines], draw, { deep: true });
</script>

<template>
    <div ref="el" class="sc-chart" :style="{ height: height + 'px' }"></div>
</template>
