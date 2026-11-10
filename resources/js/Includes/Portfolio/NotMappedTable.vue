<script setup>
import { reactive, ref } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice } from '@/Service/Utils/Format.js';

const props = defineProps({
    rows:    { type: Array,   default: () => [] },
    buckets: { type: Array,   default: () => [] },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

// Per-row choices, keyed by not-mapped id. Unset = defaults (a bucket already holding it, full quantity)
const target = reactive({});
const qty    = reactive({});
const busy   = ref(0);

const getTarget = (r) => target[r.id] ?? (r.targets.length ? r.targets[0].bucket_id : '');
const getQty    = (r) => qty[r.id] ?? r.quantity;

// label shows what happens: add to an existing holding, or create it in that bucket
function bucketLabel(r, b) {
    const t = r.targets.find(x => x.bucket_id === b.id);
    return t ? `${b.name} (holds ${fmtNum(t.quantity)})` : `${b.name} (new)`;
}

function shift(r) {
    const dest = getTarget(r);
    if (!dest) {
        alert('Select a bucket');
        return;
    }

    busy.value = r.id;
    DBService.postData('/api/portfolio/not-mapped/shift', { id: r.id, quantity: getQty(r), bucket_id: Number(dest) }).then((data) => {
        busy.value = 0;
        if (!data.success) alert(data.message);
        emit('changed');
    });
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="min-width:160px">Symbol</th>
                    <th class="text-end">Not Mapped Qty</th>
                    <th class="text-end">Avg Price</th>
                    <th class="text-end">CSV Qty</th>
                    <th class="text-end">In Buckets</th>
                    <th style="min-width:230px">Shift To</th>
                    <th>Qty</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="8" class="text-center py-5 text-muted">
                        <span class="spinner-border spinner-border-sm me-2"></span> Loading…
                    </td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td colspan="8" class="text-center py-5 text-muted">Nothing is unmapped</td>
                </tr>
                <template v-else>
                    <tr v-for="r in rows" :key="r.id">
                        <td><span class="cell-primary mono">{{ r.symbol }}</span></td>
                        <td class="text-num fw-semibold">{{ fmtNum(r.quantity) }}</td>
                        <td class="text-num">{{ fmtPrice(r.avg_price) }}</td>
                        <td class="text-num text-muted">{{ fmtNum(r.uploaded_qty) }}</td>
                        <td class="text-num text-muted">{{ fmtNum(r.bucket_qty) }}</td>
                        <td>
                            <select
                                class="form-select form-select-sm pf-inline-select"
                                :value="getTarget(r)"
                                @change="target[r.id] = $event.target.value"
                            >
                                <option v-if="!r.targets.length" value="">Select bucket…</option>
                                <option v-for="b in buckets" :key="b.id" :value="b.id">{{ bucketLabel(r, b) }}</option>
                            </select>
                        </td>
                        <td>
                            <input
                                type="number" min="1" :max="r.quantity"
                                class="form-control form-control-sm pf-inline-input"
                                :value="getQty(r)"
                                @input="qty[r.id] = $event.target.value"
                            />
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-primary" :disabled="busy === r.id" @click="shift(r)">
                                <span v-if="busy === r.id" class="spinner-border spinner-border-sm"></span>
                                <template v-else>Shift</template>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
