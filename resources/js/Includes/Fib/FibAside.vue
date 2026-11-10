<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { fmtPrice, fmtDate } from '@/Service/Utils/Format.js';

// Fibonacci levels of ONE symbol: the saved swings (show / hide / edit / delete) and a form to add one. A swing is a low and a
// high, taken either from two dates (the server reads the day's low / high from the stored daily candles) or typed in.
// The levels are stored by the server; the charts draw them on every timeframe.
const props = defineProps({
    show: { type: Boolean, required: true },
    item: { type: Object,  default: null },      // { exchange, symbol }
    top:  { type: Boolean, default: false },     // opened from inside the chart popup → stack above it
});

const emit = defineEmits(['close', 'changed', 'picking']);   // changed(swings) — what the charts should draw now; picking(step | null) — waiting for a candle click

const { handleSubmit, resetForm } = useForm();

const fibs    = ref([]);
const loading = ref(false);
const busy    = ref(false);
const open    = ref({});      // swing id → its level table is expanded
const error   = ref('');
const notice  = ref('');

const blank = () => ({ id: null, mode: 'dates', label: '', low_date: '', high_date: '', low_price: '', high_price: '', direction: 'up' });
const model = ref(blank());

const modeOptions = [
    { value: 'dates',  label: 'From two dates (reads the daily candles)' },
    { value: 'values', label: 'Type the low and high prices' },
];
const directionOptions = [
    { value: 'up',   label: 'Up swing — the low came first' },
    { value: 'down', label: 'Down swing — the high came first' },
];

const body = () => ({ symbol: props.item.symbol, exchange: props.item.exchange });

// ─── Picking the low / high by clicking candles on the charts ────────
// While picking, the drawer slides out of the way (it stays mounted, so the form keeps its values) and a banner says what to click.
// The charts report the clicked candle through onPicked(). The candle's LOW is used for the low, its HIGH for the high.
// Two daily candles → "from two dates" (exactly what the server would read); anything else → the prices themselves.
const picking  = ref(null);                       // null | 'low' | 'high' | 'both-low' | 'both-high'
const pickInfo = ref({ low: null, high: null });  // { timeframe, date, price } of the candle picked for each

const TF = { daily: 'daily', weekly: 'weekly', monthly: 'monthly' };
const pickText = (r) => `${fmtPrice(r.price)} (${TF[r.timeframe] || r.timeframe} candle ${fmtDate(r.date)})`;

function startPick(which) {
    error.value = notice.value = '';
    picking.value = which === 'both' ? 'both-low' : which;
}

function cancelPick() { picking.value = null; }

function onPicked(c) {
    const step = picking.value;
    if (!step) return;
    const isLow = step === 'low' || step === 'both-low';
    const rec = { timeframe: c.timeframe, date: c.date, price: isLow ? c.low : c.high };

    if (isLow) { pickInfo.value.low = rec; model.value.low_price = rec.price; }
    else       { pickInfo.value.high = rec; model.value.high_price = rec.price; }

    if (step === 'both-low') { picking.value = 'both-high'; return; }   // now the high
    picking.value = null;
    applyPicks();
}

function applyPicks() {
    const { low, high } = pickInfo.value;
    const m = model.value;
    m.mode = 'values';
    if (!low || !high) return;

    // which came first decides the swing's direction
    m.direction = low.date > high.date ? 'down' : 'up';
    if (high.price <= low.price) { error.value = `The high (${fmtPrice(high.price)}) must be above the low (${fmtPrice(low.price)}) — pick them again`; return; }

    if (low.timeframe === 'daily' && high.timeframe === 'daily' && low.date !== high.date) {
        m.mode = 'dates';
        m.low_date = low.date;
        m.high_date = high.date;
    } else if (low.date === high.date && low.timeframe === high.timeframe) {
        notice.value = 'Both are the same candle, so its direction is not known — it is set to an up swing; change it below if it was a down swing.';
    }
}

function onKey(e) {
    if (picking.value && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelPick(); }
}

watch(picking, (v) => {
    emit('picking', v);
    if (v) window.addEventListener('keydown', onKey, true); else window.removeEventListener('keydown', onKey, true);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));

defineExpose({ onPicked });

watch(() => props.show, (val) => {
    if (!val) picking.value = null;
    if (!val || !props.item) return;
    resetForm();
    pickInfo.value = { low: null, high: null };
    model.value = blank();
    error.value = notice.value = '';
    fibs.value = [];
    loading.value = true;
    DBService.postData('/api/fib/list', body()).then((data) => {
        loading.value = false;
        if (data.success) fibs.value = data.fibs;
        else error.value = data.message;
    });
});

// every change returns the symbol's full list + the lines to draw
function applyReply(data) {
    fibs.value = data.fibs;
    emit('changed', data.chart);
}

const submit = handleSubmit(() => {
    error.value = notice.value = '';
    const m = model.value;
    if (m.mode !== 'dates' && m.mode !== 'values') { error.value = 'Choose how to take the low and high'; return; }
    if (m.mode === 'dates' && (!m.low_date || !m.high_date)) { error.value = 'Pick both the low date and the high date'; return; }
    if (m.mode === 'values' && (m.low_price === '' || m.high_price === '')) { error.value = 'Enter both the low and the high price'; return; }

    busy.value = true;
    DBService.postData('/api/fib/save', { ...body(), ...m }).then((data) => {
        busy.value = false;
        if (!data.success) { error.value = data.message; return; }
        applyReply(data);
        notice.value = data.message;
        resetForm();
        pickInfo.value = { low: null, high: null };
        model.value = blank();
    });
});

function edit(f) {
    error.value = notice.value = '';
    pickInfo.value = { low: null, high: null };
    model.value = {
        id: f.id, mode: f.source, label: f.label || '', low_date: f.low_date || '', high_date: f.high_date || '',
        low_price: f.low_price, high_price: f.high_price, direction: f.direction,
    };
}

function cancelEdit() { model.value = blank(); pickInfo.value = { low: null, high: null }; error.value = ''; }

function toggle(f) {
    DBService.postData('/api/fib/toggle', { id: f.id, shown: f.is_shown ? 0 : 1 }).then((data) => {
        if (data.success) applyReply(data); else error.value = data.message;
    });
}

function remove(f) {
    if (!confirm(`Delete this Fibonacci swing (${fmtPrice(f.low_price)} – ${fmtPrice(f.high_price)})?`)) return;
    DBService.postData('/api/fib/delete', { id: f.id }).then((data) => {
        if (!data.success) { error.value = data.message; return; }
        applyReply(data);
        if (model.value.id === f.id) model.value = blank();
    });
}

// 0.618 → 61.8%
const pct = (r) => (Math.round(r * 1000) / 10) + '%';
// 0% is where the swing started, 100% where it ended; beyond that are the extensions
const levelName = (l) => l.ratio === 0 ? 'Swing start' : l.ratio === 1 ? 'Swing end' : l.kind === 'extension' ? 'Extension' : 'Retracement';
</script>

<template>
    <Aside :show="show" :top="top" :away="!!picking" title="Fibonacci levels" :subtitle="item ? `${item.exchange}:${item.symbol} — drawn on the daily, weekly and monthly charts` : ''" @close="emit('close')">

        <!-- saved swings -->
        <div v-if="loading" class="text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</div>
        <template v-else>
            <div v-if="!fibs.length" class="text-muted mb-3" style="font-size:13px">No Fibonacci swing saved for this symbol yet.</div>

            <div v-for="(f, n) in fibs" :key="f.id" class="fib-card" :class="{ 'is-hidden': !f.is_shown, 'is-editing': model.id === f.id }">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold" style="font-size:13px">{{ f.label || `Swing ${n + 1}` }}</span>
                    <span class="status-chip" :class="f.direction === 'up' ? 'chip-green' : 'chip-red'">{{ f.direction === 'up' ? 'Up swing' : 'Down swing' }}</span>
                    <div class="ms-auto d-flex gap-1">
                        <button class="tbl-icon-btn" :title="f.is_shown ? 'Hide from the charts' : 'Show on the charts'" @click="toggle(f)"><i class="bi" :class="f.is_shown ? 'bi-eye' : 'bi-eye-slash'"></i></button>
                        <button class="tbl-icon-btn" title="Edit" @click="edit(f)"><i class="bi bi-pencil"></i></button>
                        <button class="tbl-icon-btn tbl-icon-btn-danger" title="Delete" @click="remove(f)"><i class="bi bi-trash"></i></button>
                    </div>
                </div>

                <div class="fib-swing mono">
                    <template v-if="f.direction === 'up'">
                        <span>Low {{ fmtPrice(f.low_price) }}<small v-if="f.low_date"> · {{ fmtDate(f.low_date) }}</small></span>
                        <i class="bi bi-arrow-right"></i>
                        <span>High {{ fmtPrice(f.high_price) }}<small v-if="f.high_date"> · {{ fmtDate(f.high_date) }}</small></span>
                    </template>
                    <template v-else>
                        <span>High {{ fmtPrice(f.high_price) }}<small v-if="f.high_date"> · {{ fmtDate(f.high_date) }}</small></span>
                        <i class="bi bi-arrow-right"></i>
                        <span>Low {{ fmtPrice(f.low_price) }}<small v-if="f.low_date"> · {{ fmtDate(f.low_date) }}</small></span>
                    </template>
                </div>

                <button class="fib-more" @click="open[f.id] = !open[f.id]">
                    <i class="bi" :class="open[f.id] ? 'bi-chevron-down' : 'bi-chevron-right'"></i> {{ f.levels.length }} levels
                </button>
                <table v-if="open[f.id]" class="trade-kv fib-levels">
                    <tbody>
                        <tr v-for="l in f.levels" :key="l.kind + l.ratio">
                            <td class="mono">{{ pct(l.ratio) }}</td>
                            <td class="mono fw-semibold">{{ fmtPrice(l.price) }}</td>
                            <td class="text-muted">{{ levelName(l) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- add / edit -->
        <div class="trade-sub mt-4 mb-2">{{ model.id ? 'Edit swing' : 'Add a swing' }}</div>

        <div v-if="error" class="trade-note warn mb-2">{{ error }}</div>
        <div v-else-if="notice" class="trade-note mb-2">{{ notice }}</div>

        <!-- click the candles on the charts instead of typing -->
        <div class="fib-pick">
            <div class="fib-pick-title"><i class="bi bi-cursor"></i> Pick from the chart</div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn-secondary-sm" :disabled="busy" title="Click the low candle, then the high candle" @click="startPick('both')"><i class="bi bi-hand-index"></i> Low, then high</button>
                <button type="button" class="btn-secondary-sm" :disabled="busy" title="Click just the low candle" @click="startPick('low')">Low only</button>
                <button type="button" class="btn-secondary-sm" :disabled="busy" title="Click just the high candle" @click="startPick('high')">High only</button>
            </div>
            <div v-if="pickInfo.low || pickInfo.high" class="fib-pick-info mono">
                <span v-if="pickInfo.low">Low {{ pickText(pickInfo.low) }}</span>
                <span v-if="pickInfo.high">High {{ pickText(pickInfo.high) }}</span>
            </div>
            <div class="text-muted" style="font-size:12px">The drawer steps aside; click a candle on any chart (the low of the candle is used for the low, its high for the high), then check the values and press Add swing.</div>
        </div>

        <form id="fibForm" novalidate @submit.prevent="submit()">
            <div class="row g-3">
                <FormSelect label="Take the low and high" name="mode" cls="col-12" v-model="model.mode" :options="modeOptions" :disabled="busy" />

                <template v-if="model.mode === 'dates'">
                    <div class="form-group col-6">
                        <label>Low date <span class="text-danger">*</span></label>
                        <input v-model="model.low_date" type="date" class="form-control" :disabled="busy" />
                    </div>
                    <div class="form-group col-6">
                        <label>High date <span class="text-danger">*</span></label>
                        <input v-model="model.high_date" type="date" class="form-control" :disabled="busy" />
                    </div>
                    <p class="col-12 text-muted mb-0" style="font-size:12px">
                        Uses the <strong>low</strong> of the daily candle on the low date and the <strong>high</strong> of the candle on the high date.
                        If the high date comes first it is a down swing.
                    </p>
                </template>

                <template v-else-if="model.mode === 'values'">
                    <FormInput label="Low price" name="low_price" type="number" :req="true" v-model="model.low_price" cls="col-6" :disabled="busy" />
                    <FormInput label="High price" name="high_price" type="number" :req="true" v-model="model.high_price" cls="col-6" :disabled="busy" />
                    <FormSelect label="Swing" name="direction" cls="col-12" v-model="model.direction" :options="directionOptions" :disabled="busy" />
                </template>

                <FormInput label="Label (optional)" name="label" v-model="model.label" cls="col-12" placeholder="e.g. Main uptrend" :disabled="busy" />

                <p class="col-12 text-muted mb-0" style="font-size:12px">
                    0% is where the swing started and 100% where it ended (an up swing runs from its low to its high). 138.2% and 161.8% project it further.
                </p>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="fibForm" class="aside-btn-primary" :disabled="busy">{{ busy ? 'Saving…' : (model.id ? 'Save changes' : 'Add swing') }}</button>
                <button v-if="model.id" type="button" class="aside-btn-cancel" :disabled="busy" @click="cancelEdit">Cancel edit</button>
                <button v-else type="button" class="aside-btn-cancel" @click="emit('close')">Close</button>
            </div>
        </template>
    </Aside>

    <!-- what to click while the drawer is out of the way -->
    <teleport to="body">
        <div v-if="picking" class="fib-pick-banner">
            <i class="bi bi-hand-index"></i>
            <div>
                <template v-if="picking === 'low' || picking === 'both-low'">Click the candle for the swing <strong>LOW</strong></template>
                <template v-else>
                    <template v-if="pickInfo.low && picking === 'both-high'">Low {{ pickText(pickInfo.low) }} ✓ — now </template>Click the candle for the swing <strong>HIGH</strong>
                </template>
                <small>on any chart · Esc to cancel</small>
            </div>
            <button type="button" class="btn-secondary-sm" @click="cancelPick">Cancel</button>
        </div>
    </teleport>
</template>
