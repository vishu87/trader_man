<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { todayString } from '@/Service/Utils/Format.js';

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

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    error.value = '';
    model.value = { symbol: '', exchange: 'NSE', notes: '', qty: '', price: '', charges: '', action_date: todayString() };
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
