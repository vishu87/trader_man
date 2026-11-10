<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    show: { type: Boolean, required: true },
});

const emit = defineEmits(['close', 'uploaded']);

const fileInput = ref(null);
const file      = ref(null);
const loading   = ref(false);
const summary   = ref(null);
const dragging  = ref(false);

watch(() => props.show, (val) => {
    if (val) {
        file.value    = null;
        summary.value = null;
    }
});

function pick(f) {
    if (!f) return;
    if (!f.name.toLowerCase().endsWith('.csv')) {
        alert('Please select a .csv file');
        return;
    }
    file.value = f;
}

function onDrop(e) {
    dragging.value = false;
    pick(e.dataTransfer.files[0]);
}

function onPicked(e) {
    pick(e.target.files[0]);
    e.target.value = '';
}

async function upload() {
    if (!file.value) {
        alert('Please select a CSV file');
        return;
    }
    loading.value = true;

    const fd = new FormData();
    fd.append('file', file.value);

    const res = await axios.post(base_url + '/api/portfolio/upload', fd, {
        headers: { apiToken: api_key },
    }).then(r => r.data).catch(e => ({ success: false, message: e.message }));

    loading.value = false;

    if (!res.success) {
        alert(res.message);
        return;
    }

    summary.value = res.summary;
    emit('uploaded', res.summary);
}
</script>

<template>
    <Aside
        :show="show"
        title="Upload Holdings CSV"
        subtitle="Zerodha holdings export — compared against all buckets"
        size="sm"
        @close="emit('close')"
    >
        <!-- Result -->
        <div v-if="summary" class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-2 text-success fw-semibold">
                <i class="bi bi-check-circle-fill"></i> Holdings reconciled
            </div>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td>Matching (nothing to do)</td><td class="text-end mono">{{ summary.matched }}</td></tr>
                    <tr><td>Not mapped</td><td class="text-end mono">{{ summary.not_mapped }}</td></tr>
                    <tr><td>Removal</td><td class="text-end mono">{{ summary.removals }}</td></tr>
                    <tr v-if="summary.skipped"><td>Skipped rows</td><td class="text-end mono">{{ summary.skipped }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Picker -->
        <div v-else class="d-flex flex-column gap-3">
            <div class="p-3 rounded" style="background:#f8fafc;font-size:13px;color:#475467;border:1px solid #E7EBF1">
                <p class="mb-2">For each symbol, the CSV <code>Qty.</code> is compared with the total quantity across <strong>all buckets</strong>:</p>
                <ul class="mb-2 ps-3">
                    <li>Same → nothing happens</li>
                    <li>CSV has more → difference goes to <strong>Not Mapped</strong></li>
                    <li>CSV has less (or symbol missing) → difference goes to <strong>Removal</strong></li>
                </ul>
                <p class="mb-0 text-muted">Needs <code>Instrument</code> and <code>Qty.</code> columns. Every upload <strong>replaces</strong> the current Not Mapped and Removal lists.</p>
            </div>

            <div
                class="rounded text-center p-4"
                :style="{ border: '2px dashed ' + (dragging ? '#2E6BFF' : '#D0D5DD'), background: dragging ? '#EEF4FF' : '#fff', cursor: 'pointer' }"
                @click="fileInput.click()"
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="onDrop"
            >
                <i class="bi bi-file-earmark-spreadsheet" style="font-size:28px;color:#667085"></i>
                <div v-if="file" class="mt-2 fw-semibold">{{ file.name }}</div>
                <div v-else class="mt-2 text-muted">Click or drop a .csv file here</div>
            </div>
            <input ref="fileInput" type="file" accept=".csv" class="d-none" @change="onPicked" />
        </div>

        <template #footer>
            <div class="d-flex gap-2">
                <button v-if="!summary" type="button" class="aside-btn-primary" :disabled="loading || !file" @click="upload">
                    {{ loading ? 'Uploading…' : 'Upload & Reconcile' }}
                </button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">
                    {{ summary ? 'Done' : 'Cancel' }}
                </button>
            </div>
        </template>
    </Aside>
</template>
