<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const props = defineProps({
    show:              { type: Boolean, required: true },
    holding:           { type: Object,  default: null },   // null = add
    buckets:           { type: Array,   default: () => [] },
    default_bucket_id: { type: [Number, String], default: '' },
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});

const followOptions = [
    { value: 0, label: 'No' },
    { value: 1, label: 'Yes — show in followed charts' },
];

const typeOptions = [
    { value: 0, label: 'Portfolio' },
    { value: 1, label: 'Tracking only (not counted in totals)' },
];

const exchangeOptions = [
    { value: 'NSE', label: 'NSE' },
    { value: 'BSE', label: 'BSE' },
];

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    model.value = props.holding
        ? {
            id:             props.holding.id,
            bucket_id:      props.holding.bucket_id,
            symbol:         props.holding.symbol,
            zerodha_symbol: props.holding.zerodha_symbol,
            exchange:       props.holding.exchange,
            quantity:       props.holding.quantity,
            avg_price:      props.holding.avg_price,
            is_tracking:    props.holding.is_tracking ? 1 : 0,
            follow:         props.holding.follow ? 1 : 0,
        }
        : {
            bucket_id:      props.default_bucket_id || '',
            symbol:         '',
            zerodha_symbol: '',
            exchange:       'NSE',
            quantity:       0,
            avg_price:      0,
            is_tracking:    0,
            follow:         0,
        };
});

const submit = handleSubmit(() => {
    loading.value = true;
    DBService.postData('/api/portfolio/holding/save', model.value).then((data) => {
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
    <Aside
        :show="show"
        :title="holding ? 'Edit Stock' : 'Add Stock'"
        subtitle="LTP is fetched from Kite using the Zerodha symbol"
        size="sm"
        @close="emit('close')"
    >
        <!-- novalidate: prices are decimal, native number validation would reject them -->
        <form @submit.prevent="submit()" id="holdingForm" novalidate>
            <div class="row g-3">
                <FormSelect
                    label="Bucket" name="bucket_id" :req="true" cls="col-12"
                    v-model="model.bucket_id" :options="buckets" opt_id="id" opt_name="name"
                    :disabled="loading"
                />
                <FormInput label="Symbol" name="symbol" :req="true" v-model="model.symbol" cls="col-12" :disabled="loading" />
                <FormInput
                    label="Zerodha Symbol" name="zerodha_symbol" v-model="model.zerodha_symbol"
                    placeholder="Same as Symbol if left blank" cls="col-md-8" :disabled="loading"
                />
                <FormSelect
                    label="Exchange" name="exchange" :req="true" cls="col-md-4"
                    v-model="model.exchange" :options="exchangeOptions" :disabled="loading"
                />
                <FormSelect
                    label="Type" name="is_tracking" :req="true" cls="col-12"
                    v-model="model.is_tracking" :options="typeOptions" :disabled="loading"
                />
                <FormSelect
                    label="Follow" name="follow" :req="true" cls="col-12"
                    v-model="model.follow" :options="followOptions" :disabled="loading"
                />
                <FormInput label="Quantity" name="quantity" type="number" :req="true" v-model="model.quantity" cls="col-md-6" :disabled="loading" />
                <FormInput label="Average Price" name="avg_price" type="number" :req="true" v-model="model.avg_price" cls="col-md-6" :disabled="loading" />
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="holdingForm" class="aside-btn-primary" :disabled="loading">
                    {{ loading ? 'Saving…' : 'Save' }}
                </button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
