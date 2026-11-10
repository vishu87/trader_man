<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const props = defineProps({
    show:            { type: Boolean, required: true },
    watchlists:      { type: Array,   default: () => [] },
    default_list_id: { type: [Number, String], default: '' },
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});
const result  = ref(null);

const followOptions = [
    { value: 0, label: 'No' },
    { value: 1, label: 'Yes' },
];

const exchangeOptions = [
    { value: 'NSE', label: 'NSE' },
    { value: 'BSE', label: 'BSE' },
];

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    result.value = null;
    model.value = { watchlist_id: props.default_list_id || '', exchange: 'NSE', follow: 0, text: '' };
});

const submit = handleSubmit(() => {
    if (!model.value.text.trim()) {
        alert('Paste at least one symbol');
        return;
    }
    loading.value = true;
    DBService.postData('/api/watchlist/bulk-add', model.value).then((data) => {
        loading.value = false;
        if (!data.success) {
            alert(data.message);
            return;
        }
        result.value = data;
        emit('saved');
    });
});
</script>

<template>
    <Aside :show="show" title="Bulk Add Symbols" subtitle="One symbol per line" @close="emit('close')">

        <!-- Result -->
        <div v-if="result" class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-2 fw-semibold" :class="result.added ? 'text-success' : 'text-warning'">
                <i class="bi" :class="result.added ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'"></i>
                {{ result.added }} added<span v-if="result.skipped.length">, {{ result.skipped.length }} skipped</span>
            </div>

            <div v-if="result.skipped.length">
                <p class="aside-section-title">Skipped</p>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr v-for="(s, i) in result.skipped" :key="i">
                            <td class="mono">{{ s.line }}</td>
                            <td class="text-muted">{{ s.reason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-muted mb-0" style="font-size:12.5px">
                A symbol Kite does not recognise will show an LTP of <span class="status-chip chip-red">-1</span> in the list — check its spelling or exchange.
            </p>
        </div>

        <!-- Form -->
        <form v-else @submit.prevent="submit()" id="bulkAddForm" novalidate>
            <div class="row g-3">
                <FormSelect label="Watchlist" name="watchlist_id" :req="true" cls="col-md-8" v-model="model.watchlist_id" :options="watchlists" opt_id="id" opt_name="name" :disabled="loading" />
                <FormSelect label="Exchange" name="exchange" :req="true" cls="col-md-4" v-model="model.exchange" :options="exchangeOptions" :disabled="loading" />

                <FormSelect label="Follow all of these" name="follow" :req="true" cls="col-12" v-model="model.follow" :options="followOptions" :disabled="loading" />

                <div class="col-12 form-group">
                    <label>Symbols <span class="text-danger">*</span></label>
                    <textarea
                        class="form-control mono" v-model="model.text" rows="14" :disabled="loading" spellcheck="false"
                        placeholder="INFY&#10;TCS, 3500, 3300&#10;BSE:RELIANCE&#10;NSE:ITC, 400"
                    ></textarea>
                </div>

                <div class="col-12 text-muted" style="font-size:12.5px">
                    <div><code>SYMBOL</code> — one per line.</div>
                    <div><code>SYMBOL, buy price, stop loss</code> — optional prices after a comma or tab.</div>
                    <div><code>BSE:SYMBOL</code> — overrides the exchange chosen above for that line.</div>
                    <div class="mt-1">Symbols already in the watchlist are skipped.</div>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button v-if="!result" type="submit" form="bulkAddForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Adding…' : 'Add Symbols' }}</button>
                <button v-else type="button" class="aside-btn-primary" @click="result = null">Add More</button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">{{ result ? 'Done' : 'Cancel' }}</button>
            </div>
        </template>
    </Aside>
</template>
