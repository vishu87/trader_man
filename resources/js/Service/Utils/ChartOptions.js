// ECharts setup + option builder for the candlestick charts (modelled on the futures app's graph-print page).

import * as echarts from 'echarts/core';
import { CandlestickChart, LineChart, BarChart } from 'echarts/charts';
import {
    GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, MarkLineComponent, MarkAreaComponent, AxisPointerComponent, TitleComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    CandlestickChart, LineChart, BarChart,
    GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, MarkLineComponent, MarkAreaComponent, AxisPointerComponent, TitleComponent,
    CanvasRenderer,
]);

export { echarts };

const UP   = '#0E9F5B';
const DOWN = '#E03131';

// Volume bar colour: green/red by candle direction, stronger the further volume is above its 21-period average
function volumeColor(open, close, volume, ma) {
    const up = close >= open;
    if (!ma) return up ? '#83e087' : '#fa7b7b';

    if (up) {
        if (volume > ma * 3)   return '#FFEB3B';
        if (volume > ma * 1.5) return '#006400';
        if (volume > ma * 0.5) return '#00ff00';
        return '#83e087';
    }
    if (volume > ma * 3)   return '#cf0f0f';
    if (volume > ma * 1.5) return '#d94444';
    if (volume > ma * 0.5) return '#FF0000';
    return '#fa7b7b';
}

// Fibonacci drawing, in the style of Kite / TradingView: a trendline from the swing's start to its end, a horizontal line at each
// level running from the swing out to the right edge, shaded bands between neighbouring levels, and each level's label in a gap
// to the right of the chart.
const FIB_LINE   = '#8A94A6';
const FIB_ANCHOR = '#344054';        // the 0% and 100% lines
const FIB_BAND   = '46, 107, 255';   // rgb of the shading

const pctText = (ratio) => `${+(ratio * 100).toFixed(1)}%`;

/** Index of the candle a date falls in: the last category dated on or before it (0 when it is before the first, or unknown). */
export function candleIndex(xAxis, date) {
    if (!date) return 0;
    let found = 0;
    for (let i = 0; i < xAxis.length; i++) {
        if (xAxis[i] <= date) found = i; else break;
    }
    return found;
}

/**
 * The markLine / markArea data for the Fibonacci swings.
 * @param swings  [{label, direction, start:{date,price}, end:{date,price}, levels:[{ratio, price, extension}]}]
 * @param xAxis   the candles' dates (ISO strings, oldest first)
 * @param lastIdx index of the last x position to draw to (the end of the empty gap on the right)
 * @param range   [min, max] of the y axis: a level outside it is not drawn (it would sit off the chart)
 */
export function fibShapes(swings, xAxis, lastIdx, range, compact = false) {
    const lines = [];
    const areas = [];
    const fontSize = compact ? 9 : 10.5;

    for (const sw of swings) {
        const startIdx = candleIndex(xAxis, sw.start && sw.start.date);
        const prefix = sw.label ? `${sw.label} · ` : '';
        const levels = sw.levels.filter(l => l.price >= range[0] && l.price <= range[1]).sort((a, b) => a.price - b.price);

        // the swing itself, start → end
        if (sw.start && sw.end && sw.start.date && sw.end.date && [sw.start.price, sw.end.price].every(p => p >= range[0] && p <= range[1])) {
            const trend = { lineStyle: { color: FIB_LINE, type: 'dashed', width: 1 }, label: { show: false } };
            lines.push([
                { coord: [startIdx, sw.start.price], ...trend },
                { coord: [candleIndex(xAxis, sw.end.date), sw.end.price], ...trend },
            ]);
        }

        // shaded bands between neighbouring levels, a little stronger between 0% and 100% than in the extension zone
        for (let i = 0; i + 1 < levels.length; i++) {
            const inside = !levels[i].extension && !levels[i + 1].extension;
            const alpha = (inside ? 0.07 : 0.035) + (i % 2 ? 0.025 : 0);
            areas.push([
                { xAxis: startIdx, yAxis: levels[i].price, itemStyle: { color: `rgba(${FIB_BAND}, ${alpha})` } },
                { xAxis: lastIdx, yAxis: levels[i + 1].price },
            ]);
        }

        // a line per level; its label sits at the right end, outside the plot
        for (const l of levels) {
            const anchor = l.ratio === 0 || l.ratio === 1;
            const style = {
                lineStyle: { color: anchor ? FIB_ANCHOR : FIB_LINE, type: 'solid', width: anchor ? 1.2 : 1 },
                label: { show: true, position: 'end', color: anchor ? FIB_ANCHOR : '#5B6577', fontSize, formatter: `${prefix}${pctText(l.ratio)}  ${l.price}` },
            };
            lines.push([{ coord: [startIdx, l.price], ...style }, { coord: [lastIdx, l.price], ...style }]);
        }
    }

    return { lines, areas };
}

/**
 * @param data  response of /api/charts/candles
 * @param marks price lines [{label, price, color}] — avg price, buy price, stop loss
 * @param opts  { fibs: [swing] } Fibonacci swings (see fibShapes), drawn on every timeframe: the chart gets an empty gap on its
 *              right for them to run into and for their labels;
 *              { rsi: true } adds an RSI pane under the price (RSI 14 with its 20-period average, from data.rsi);
 *              { compact: true } for the popup, where three charts share one laptop screen: smaller text, a tighter legend,
 *              and the plot given more of the width
 */
export function buildOption(data, marks = [], opts = {}) {
    const compact = !!opts.compact;
    const candles = data.candles;
    const rsi = opts.rsi && data.rsi ? data.rsi : null;   // RSI pane: RSI(period) + its average, aligned with the candles

    // Y range from the candles; a price line only stretches the axis when it is near the price action,
    // so a far-away stop loss does not squash the candles flat. Fibonacci levels follow the same rule.
    let lo = Math.min(...candles.map(c => c[2]));
    let hi = Math.max(...candles.map(c => c[3]));
    const lastClose = candles[candles.length - 1][1];
    const swings = opts.fibs || [];
    const isNear = (price) => Math.abs(price - lastClose) / lastClose <= 0.3;
    const near = marks.filter(m => isNear(m.price));
    const nearFibPrices = swings.flatMap(sw => sw.levels.map(l => l.price)).filter(isNear);
    [...near.map(m => m.price), ...nearFibPrices].forEach(p => { lo = Math.min(lo, p); hi = Math.max(hi, p); });
    const pad = (hi - lo) * 0.04 || hi * 0.02;
    const yMin = Math.floor(lo - pad);
    const yMax = Math.ceil(hi + pad);

    // Fibonacci: an empty stretch to the right of the last candle (the levels run into it) plus a margin for the labels
    const fib = swings.length ? fibShapes(swings, data.x_axis, 0, [yMin, yMax], compact) : { lines: [], areas: [] };
    const hasFib = fib.lines.length > 0;
    const gapBars = hasFib ? Math.max(6, Math.round(candles.length * 0.1)) : 0;
    const xData = hasFib ? [...data.x_axis, ...Array.from({ length: gapBars }, (_, i) => ' '.repeat(i + 1))] : data.x_axis;   // distinct blanks
    const lastIdx = xData.length - 1;
    const shapes = hasFib ? fibShapes(swings, data.x_axis, lastIdx, [yMin, yMax], compact) : fib;
    const rightMargin = compact ? 96 : 118;   // room for the level labels

    const series = [
        {
            name: 'Price',
            type: 'candlestick',
            data: candles,
            itemStyle: { color: UP, color0: DOWN, borderColor: UP, borderColor0: DOWN },
            markLine: {
                symbol: 'none',
                silent: true,
                data: [
                    ...near.map(m => ({
                        yAxis: m.price,
                        lineStyle: { color: m.color, type: 'dashed', width: 1.2 },
                        label: { formatter: `${m.label} ${m.price}`, position: 'insideStartTop', color: m.color, fontSize: compact ? 10 : 11 },
                    })),
                    ...shapes.lines,
                ],
            },
            markArea: { silent: true, data: shapes.areas },
        },
        ...data.lines.map(l => ({
            name: l.name,
            type: 'line',
            data: l.data,
            smooth: true,
            showSymbol: false,
            lineStyle: { width: 1, color: l.color },
            itemStyle: { color: l.color },
        })),
        {
            name: 'Volume',
            type: 'bar',
            xAxisIndex: 1,
            yAxisIndex: 1,
            data: data.volumes.map((v, i) => ({
                value: v,
                itemStyle: { color: volumeColor(candles[i][0], candles[i][1], v, data.volume_ma21[i]) },
            })),
        },
    ];

    // RSI pane (its own grid, index 2): the RSI line and its average, with dashed guides at 30 and 70
    if (rsi) {
        const guide = { symbol: 'none', silent: true, lineStyle: { color: '#B6BECB', type: 'dashed', width: 1 }, label: { show: false }, data: [{ yAxis: 30 }, { yAxis: 70 }] };
        const line = (name, values, color, width, extra = {}) => ({
            name, type: 'line', xAxisIndex: 2, yAxisIndex: 2, data: values, showSymbol: false, smooth: false,
            lineStyle: { width, color }, itemStyle: { color }, ...extra,
        });
        series.push(
            line('RSI ' + rsi.period, rsi.rsi, '#1B2A4A', 1.2, { markLine: guide }),
            line('RSI avg ' + rsi.avg_period, rsi.avg, '#E03131', 1),
        );
    }

    const axis = (gridIndex, show) => ({
        type: 'category',
        gridIndex,
        data: xData,
        boundaryGap: true,
        scale: true,
        axisLine: { onZero: false },
        axisTick: { show },
        axisLabel: { show, fontSize: compact ? 10 : 12 },
        splitLine: { show: false },
        // with the Fibonacci gap the axis is longer than the candles, so its end has to be stated; otherwise it follows the data
        min: hasFib ? 0 : 'dataMin',
        max: hasFib ? lastIdx : 'dataMax',
    });

    const right = (compact ? 8 : '4%');

    return {
        animation: false,
        legend: compact
            ? { top: 0, data: data.lines.map(l => l.name), selectedMode: true, itemWidth: 14, itemHeight: 8, itemGap: 8, textStyle: { fontSize: 10 } }
            : { top: 0, data: data.lines.map(l => l.name), selectedMode: true },
        tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
        axisPointer: { link: [{ xAxisIndex: 'all' }] },
        // price, volume and (when asked for) RSI — the RSI grid is index 2 but sits between the other two
        grid: compact
            ? [
                { left: 46, right: hasFib ? rightMargin : right, top: 26, height: rsi ? '55%' : '64%' },
                { left: 46, right: hasFib ? rightMargin : right, top: rsi ? '86%' : '80%', height: rsi ? '10%' : '14%' },
                ...(rsi ? [{ left: 46, right: hasFib ? rightMargin : right, top: '67%', height: '15%' }] : []),
            ]
            : [
                { left: '6%', right: hasFib ? rightMargin : right, top: 34, height: rsi ? '50%' : '62%' },
                { left: '6%', right: hasFib ? rightMargin : right, top: rsi ? '86%' : '78%', height: rsi ? '10%' : '16%' },
                ...(rsi ? [{ left: '6%', right: hasFib ? rightMargin : right, top: '67%', height: '15%' }] : []),
            ],
        xAxis: [axis(0, true), axis(1, false), ...(rsi ? [axis(2, false)] : [])],
        yAxis: [
            // min/max are padded (not round numbers), so hide the two end labels that would crowd the nearest round tick
            { scale: true, min: yMin, max: yMax, splitArea: { show: false }, axisLabel: { showMinLabel: false, showMaxLabel: false, fontSize: compact ? 10 : 12 } },
            { scale: true, gridIndex: 1, splitNumber: 2, axisLabel: { show: false }, axisLine: { show: false }, axisTick: { show: false }, splitLine: { show: false } },
            // RSI runs 0–100; only the 30 and 70 guides are labelled
            ...(rsi ? [{ gridIndex: 2, min: 0, max: 100, interval: 10, axisLabel: { fontSize: compact ? 9 : 11, showMinLabel: false, showMaxLabel: false, formatter: (v) => (v === 30 || v === 70) ? v : '' }, axisLine: { show: false }, axisTick: { show: false }, splitLine: { show: false } }] : []),
        ],
        title: rsi ? { text: 'RSI ' + rsi.period, left: compact ? 50 : '7%', top: '64.5%', textStyle: { fontSize: 10, fontWeight: 'normal', color: '#667085' } } : undefined,
        // Ctrl + wheel zooms and dragging pans; a plain wheel still scrolls the page past the chart
        dataZoom: [{ type: 'inside', xAxisIndex: rsi ? [0, 1, 2] : [0, 1], zoomOnMouseWheel: 'ctrl', moveOnMouseMove: true, moveOnMouseWheel: false }],
        series,
    };
}
