<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtDate, todayString } from '@/Service/Utils/Format.js';

// Add a trade: pick the stock, optionally record the first buy right away. Further buys and sells are added from the trade's row.
const props = defineProps({
    show: { type: Boolean, required: true },
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const error   = ref('');
const model   = ref({});

const exchangeOptions = [
    { value: 'NSE', label: 'NSE' },
    { value: 'BSE', label: 'BSE' },
];

// ─── Prefill from a Zerodha buy ───
// Pick one of your Zerodha buys (partial fills of an order summed) to fill the symbol, quantity, price and date. It only copies the
// values into the form — the trade is not linked to Zerodha and nothing is tracked afterwards. Charges are not in the tradebook.
const pickOpen   = ref(false);
const pickSearch = ref('');
const pickList   = ref([]);
const pickLoading = ref(false);
const pickedKey  = ref('');     // the buy copied into the form (shown as a note)
const pickedNote = ref('');
let pickTimer = null;

function loadOrders() {
    pickLoading.value = true;
    DBService.postData('/api/trades/zerodha-orders', { search: pickSearch.value.trim() }).then((data) => {
        pickLoading.value = false;
        if (data.success) pickList.value = data.orders;
    });
}

watch(pickSearch, () => {
    clearTimeout(pickTimer);
    pickTimer = setTimeout(loadOrders, 250);
});

function togglePick() {
    pickOpen.value = !pickOpen.value;
    if (pickOpen.value && !pickList.value.length) loadOrders();
}

function pick(o) {
    if (o.has_trade) return;
    model.value.symbol      = o.symbol;
    model.value.exchange    = o.exchange;
    model.value.qty         = o.qty;
    model.value.price       = o.price;
    model.value.action_date = o.date;
    pickedKey.value  = o.key;
    pickedNote.value = `Filled from your Zerodha buy of ${o.symbol} on ${fmtDate(o.date)}. Charges are not in the tradebook — add them if you like.`;
    pickOpen.value = false;
}

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    error.value = '';
    model.value = { symbol: '', exchange: 'NSE', notes: '', qty: '', price: '', charges: '', action_date: todayString() };
    pickOpen.value = false; pickSearch.value = ''; pickList.value = []; pickedKey.value = ''; pickedNote.value = '';
});

const submit = handleSubmit(() => {
    error.value = '';
    const m = model.value;
    if ((m.qty === '') !== (m.price === '')) { error.value = 'For a first buy enter both the quantity and the price, or leave both empty'; return; }

    loading.value = true;
    DBService.postData('/api/trades/save', m).then((data) => {
        loading.value = false;
        if (data.success) {
            emit('saved', data);
            emit('close');
        } else {
            error.value = data.message;
        }
    });
});
</script>

<template>
    <Aside :show="show" title="Add Trade" subtitle="A stock you bought (or plan to track) — add its buys and sells as you make them" size="sm" @close="emit('close')">
        <form id="tradeForm" novalidate @submit.prevent="submit()">
            <div v-if="error" class="trade-note warn mb-3" style="margin-top:0">{{ error }}</div>

            <!-- optional: copy one of your Zerodha buys into the form -->
            <div class="zt-pick mb-3">
                <button type="button" class="btn-secondary-sm w-100 justify-content-center" :disabled="loading" @click="togglePick">
                    <i class="bi bi-box-arrow-in-down"></i> Pick from a Zerodha buy
                    <i class="bi ms-auto" :class="pickOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </button>
                <div v-if="pickOpen" class="zt-pick-panel">
                    <input v-model="pickSearch" type="text" class="form-control form-control-sm" placeholder="Search symbol…" />
                    <div class="zt-pick-list">
                        <div v-if="pickLoading && !pickList.length" class="zt-pick-empty"><span class="spinner-border spinner-border-sm"></span></div>
                        <div v-else-if="!pickList.length" class="zt-pick-empty">No Zerodha buys found{{ pickSearch ? ' for that symbol' : ' — upload the tradebook or sync on the Zerodha Trades page' }}</div>
                        <button
                            v-for="o in pickList" :key="o.key" type="button" class="zt-pick-row" :class="{ 'is-picked': pickedKey === o.key }"
                            :disabled="o.has_trade" :title="o.has_trade ? 'Already has an active trade — add the buy from its row' : 'Fill the form with this buy'" @click="pick(o)"
                        >
                            <span class="zt-sym mono">{{ o.symbol }}<span v-if="o.exchange !== 'NSE'" class="zt-ex">{{ o.exchange }}</span></span>
                            <span class="zt-meta">{{ fmtNum(o.qty) }} @ {{ fmtPrice(o.price) }}</span>
                            <span class="zt-date">{{ o.has_trade ? 'Active trade' : fmtDate(o.date) }}</span>
                        </button>
                    </div>
                </div>
                <p v-if="pickedNote" class="zt-pick-note">{{ pickedNote }}</p>
            </div>

            <div class="row g-3">
                <FormInput label="Symbol" name="symbol" :req="true" v-model="model.symbol" cls="col-md-8" placeholder="e.g. INFY" :disabled="loading" />
                <FormSelect label="Exchange" name="exchange" :req="true" cls="col-md-4" v-model="model.exchange" :options="exchangeOptions" :disabled="loading" />

                <div class="col-12 trade-sub" style="margin-top:6px">First buy (optional)</div>
                <FormInput label="Quantity" name="qty" type="number" v-model="model.qty" cls="col-6" :disabled="loading" />
                <FormInput label="Price" name="price" type="number" v-model="model.price" cls="col-6" :disabled="loading" />
                <div class="form-group col-6">
                    <label>Date</label>
                    <input v-model="model.action_date" type="date" class="form-control" :max="todayString()" :disabled="loading" />
                </div>
                <FormInput label="Charges (₹)" name="charges" type="number" v-model="model.charges" cls="col-6" placeholder="brokerage + taxes" :disabled="loading" />

                <FormInput label="Notes" name="notes" v-model="model.notes" cls="col-12" placeholder="why you took it (optional)" :disabled="loading" />

                <p class="col-12 text-muted mb-0" style="font-size:12px">One active trade per stock. Leave the buy empty to add it now and record the buy later.</p>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="tradeForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Saving…' : 'Add Trade' }}</button>
                <button type="button" class="aside-btn-cancel" :disabled="loading" @click="emit('close')">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
