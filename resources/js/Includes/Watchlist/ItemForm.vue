<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const props = defineProps({
    show:               { type: Boolean, required: true },
    item:               { type: Object,  default: null },   // null = add
    watchlists:         { type: Array,   default: () => [] },
    default_list_id:    { type: [Number, String], default: '' },
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});

const followOptions = [
    { value: 0, label: 'No' },
    { value: 1, label: 'Yes — show in followed charts' },
];

const exchangeOptions = [
    { value: 'NSE', label: 'NSE' },
    { value: 'BSE', label: 'BSE' },
];

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    model.value = props.item
        ? {
            id: props.item.id, watchlist_id: props.item.watchlist_id, symbol: props.item.symbol, exchange: props.item.exchange,
            buy_price: props.item.buy_price ?? '', stop_loss: props.item.stop_loss ?? '', follow: props.item.follow ? 1 : 0,
        }
        : { watchlist_id: props.default_list_id || '', symbol: '', exchange: 'NSE', buy_price: '', stop_loss: '', follow: 0 };
});

const submit = handleSubmit(() => {
    loading.value = true;
    DBService.postData('/api/watchlist/item/save', model.value).then((data) => {
        loading.value = false;
        if (data.success) {
            emit('saved');
            emit('close');
        } else {
            alert(data.message);
        }
    });
});
</script>

<template>
    <Aside :show="show" :title="item ? 'Edit Symbol' : 'Add Symbol'" subtitle="Use the Zerodha trading symbol, e.g. INFY" size="sm" @close="emit('close')">
        <!-- novalidate: prices are decimal, native number validation would reject them -->
        <form @submit.prevent="submit()" id="watchItemForm" novalidate>
            <div class="row g-3">
                <FormSelect label="Watchlist" name="watchlist_id" :req="true" cls="col-12" v-model="model.watchlist_id" :options="watchlists" opt_id="id" opt_name="name" :disabled="loading" />
                <FormInput label="Symbol" name="symbol" :req="true" v-model="model.symbol" cls="col-md-8" :disabled="loading" />
                <FormSelect label="Exchange" name="exchange" :req="true" cls="col-md-4" v-model="model.exchange" :options="exchangeOptions" :disabled="loading" />
                <FormInput label="Buy Price" name="buy_price" type="number" v-model="model.buy_price" cls="col-md-6" :disabled="loading" />
                <FormInput label="Stop Loss" name="stop_loss" type="number" v-model="model.stop_loss" cls="col-md-6" :disabled="loading" />
                <FormSelect label="Follow" name="follow" :req="true" cls="col-12" v-model="model.follow" :options="followOptions" :disabled="loading" />
                <p class="col-12 text-muted mb-0" style="font-size:12px">Buy price and stop loss are optional. Stop loss must be below the buy price.</p>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="watchItemForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Saving…' : 'Save' }}</button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
