<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtDate, todayString } from '@/Service/Utils/Format.js';

// Record (or change) one buy or sell of a trade. The server refuses a sequence that would ever sell more than was held.
const props = defineProps({
    show:   { type: Boolean, required: true },
    trade:  { type: Object,  default: null },     // the trade row from /api/trades/list
    action: { type: Object,  default: null },     // null = a new entry
    side:   { type: String,  default: 'buy' },    // which side a NEW entry starts as
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const error   = ref('');
const model   = ref({});

watch(() => props.show, (val) => {
    if (!val || !props.trade) return;
    resetForm();
    error.value = '';
    const a = props.action;
    model.value = a
        ? { id: a.id, trade_id: props.trade.id, side: a.side, qty: a.qty, price: a.price, charges: a.charges || '', action_date: a.action_date, notes: a.notes || '' }
        : { trade_id: props.trade.id, side: props.side, qty: '', price: '', charges: '', action_date: todayString(), notes: '' };
    if (a) { zerodha.value = []; pickedKey.value = ''; } else loadZerodha();   // only a NEW entry can be filled from a Zerodha trade
});

// Recent Zerodha trades of this symbol: click one to fill the side, quantity, price and date. Only a copy of the values —
// the entry is not linked to Zerodha. (The tradebook has no charges, so that field stays as it is.)
const zerodha = ref([]);
const zerodhaLoading = ref(false);
const pickedKey = ref('');

function loadZerodha() {
    zerodha.value = [];
    pickedKey.value = '';
    const t = props.trade;
    if (!t) return;
    zerodhaLoading.value = true;
    DBService.postData('/api/trades/zerodha-orders', { symbol: t.symbol, exchange: t.exchange }).then((data) => {
        zerodhaLoading.value = false;
        if (data.success && props.trade && props.trade.id === t.id) zerodha.value = data.orders;
    });
}

function pick(o) {
    model.value.side = o.side;
    model.value.qty = o.qty;
    model.value.price = o.price;
    model.value.action_date = o.date;
    pickedKey.value = o.key;
}

const isSell = computed(() => model.value.side === 'sell');

// a heads-up while typing (new entries only — an edit's effect depends on its date, which the server works out)
const hint = computed(() => {
    const t = props.trade, m = model.value;
    if (!t || props.action) return '';
    const qty = Number(m.qty), price = Number(m.price);

    if (isSell.value) {
        if (!t.qty) return 'Nothing is held right now — a sell needs a buy before it.';
        let s = `You hold ${fmtNum(t.qty)} at an average of ${fmtPrice(t.avg_price)}.`;
        if (qty > t.qty) return s + ` Selling ${fmtNum(qty)} is more than that.`;
        if (qty > 0 && price > 0) {
            const pl = (price - t.avg_price) * qty;
            s += ` This sell realises about ${pl < 0 ? '−' : '+'}₹${fmtNum(Math.abs(pl))} before charges.`;
        }
        return s;
    }
    if (qty > 0 && price > 0) {
        const newAvg = (t.avg_price * t.qty + price * qty) / (t.qty + qty);
        return t.qty ? `The average cost moves from ${fmtPrice(t.avg_price)} to ${fmtPrice(newAvg)} on ${fmtNum(t.qty + qty)} shares.` : `Cost ₹${fmtNum(price * qty)}.`;
    }
    return '';
});

const submit = handleSubmit(() => {
    error.value = '';
    const m = model.value;
    if (!m.action_date) { error.value = 'Pick the date'; return; }
    if (!(Number(m.qty) > 0) || !(Number(m.price) > 0)) { error.value = 'Enter the quantity and the price'; return; }

    loading.value = true;
    DBService.postData('/api/trades/action/save', m).then((data) => {
        loading.value = false;
        if (data.success) {
            emit('saved');
            emit('close');
        } else {
            error.value = data.message;
        }
    });
});
</script>

<template>
    <Aside
        :show="show" size="sm" @close="emit('close')"
        :title="action ? 'Edit entry' : (isSell ? 'Record a sell' : 'Record a buy')"
        :subtitle="trade ? `${trade.exchange}:${trade.symbol}` : ''"
    >
        <form id="tradeActionForm" novalidate @submit.prevent="submit()">
            <div v-if="error" class="trade-note warn mb-3" style="margin-top:0">{{ error }}</div>

            <div class="side-toggle mb-3" role="group">
                <button type="button" class="side-btn buy" :class="{ on: model.side === 'buy' }" :disabled="loading" @click="model.side = 'buy'">Buy</button>
                <button type="button" class="side-btn sell" :class="{ on: model.side === 'sell' }" :disabled="loading" @click="model.side = 'sell'">Sell</button>
            </div>

            <div class="row g-3">
                <FormInput label="Quantity" name="qty" type="number" :req="true" v-model="model.qty" cls="col-6" :disabled="loading" />
                <FormInput label="Price" name="price" type="number" :req="true" v-model="model.price" cls="col-6" :disabled="loading" />
                <div class="form-group col-6">
                    <label>Date <span class="text-danger">*</span></label>
                    <input v-model="model.action_date" type="date" class="form-control" :max="todayString()" :disabled="loading" />
                </div>
                <FormInput label="Charges (₹)" name="charges" type="number" v-model="model.charges" cls="col-6" placeholder="brokerage + taxes" :disabled="loading" />
                <FormInput label="Notes" name="notes" v-model="model.notes" cls="col-12" placeholder="optional" :disabled="loading" />

                <!-- recent Zerodha trades of this symbol -->
                <div v-if="!action && (zerodhaLoading || zerodha.length)" class="col-12">
                    <div class="trade-sub" style="margin-top:2px">Recent Zerodha trades · click to fill</div>
                    <div v-if="zerodhaLoading" class="zt-pick-empty"><span class="spinner-border spinner-border-sm"></span></div>
                    <div v-else class="zt-pick-list">
                        <button v-for="o in zerodha" :key="o.key" type="button" class="zt-pick-row" :class="{ 'is-picked': pickedKey === o.key }" :disabled="loading" @click="pick(o)">
                            <span class="status-chip" :class="o.side === 'buy' ? 'chip-green' : 'chip-red'" style="min-width:48px;justify-content:center">{{ o.side === 'buy' ? 'Buy' : 'Sell' }}</span>
                            <span class="zt-meta">{{ fmtNum(o.qty) }} @ {{ fmtPrice(o.price) }}</span>
                            <span class="zt-date">{{ fmtDate(o.date) }}</span>
                        </button>
                    </div>
                </div>

                <p v-if="hint" class="col-12 mb-0" style="font-size:12.5px">{{ hint }}</p>
                <p class="col-12 text-muted mb-0" style="font-size:12px">Quantity is whole shares. Charges are optional and reduce the profit. On the same day, buys count before sells.</p>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="tradeActionForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Saving…' : (action ? 'Save changes' : (isSell ? 'Record sell' : 'Record buy')) }}</button>
                <button type="button" class="aside-btn-cancel" :disabled="loading" @click="emit('close')">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
