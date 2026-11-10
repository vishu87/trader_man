<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    show:         { type: Boolean, required: true },
    watchlist_id: { type: [Number, String], default: '' },
});

const emit = defineEmits(['close', 'uploaded']);

const fileInput = ref(null);
const file      = ref(null);
const from      = ref('2023-01-01');
const loading   = ref(false);
const result    = ref(null);
const dragging  = ref(false);

watch(() => props.show, (val) => {
    if (val) {
        file.value   = null;
        result.value = null;
    }
});

function pick(f) {
    if (!f) return;
    if (!f.name.toLowerCase().endsWith('.csv')) {
        alert('Please select the .csv file downloaded from NSE');
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
    fd.append('watchlist_id', props.watchlist_id);
    if (from.value) fd.append('from', from.value);

    const res = await axios.post(base_url + '/api/watchlist/ipo-upload', fd, {
        headers: { apiToken: api_key },
    }).then(r => r.data).catch(e => ({ success: false, message: e.message }));

    loading.value = false;

    if (!res.success) {
        alert(res.message);
        return;
    }

    result.value = res;
    emit('uploaded');
}
</script>

<template>
    <Aside :show="show" title="Upload IPO CSV" subtitle="NSE “IPO Past Issue” file — adds the IPOs not already in this list" @close="emit('close')">

        <!-- Result -->
        <div v-if="result" class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-2 fw-semibold" :class="result.added ? 'text-success' : 'text-warning'">
                <i class="bi" :class="result.added ? 'bi-check-circle-fill' : 'bi-info-circle-fill'"></i>
                {{ result.added }} added
            </div>

            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td>Rows in the file</td><td class="text-end mono">{{ result.read }}</td></tr>
                    <tr><td>Added to the list</td><td class="text-end mono">{{ result.added }}</td></tr>
                    <tr><td>Already in the list (left as they are)</td><td class="text-end mono">{{ result.existing }}</td></tr>
                    <tr><td>Skipped</td><td class="text-end mono">{{ result.skipped_count }}</td></tr>
                </tbody>
            </table>

            <div v-if="result.estimated.length" class="p-2 rounded" style="background:#FFF8E6;font-size:13px;color:#7A5200">
                <strong>{{ result.estimated.length }}</strong> had no listing date in the file, so <strong>issue end date + 3 days</strong> was used:
                <span class="mono" style="font-size:12px">{{ result.estimated.join(', ') }}</span>
            </div>

            <div v-if="result.skipped.length">
                <p class="aside-section-title">Why rows were skipped</p>
                <div v-for="(s, i) in result.skipped" :key="i" class="mb-2" style="font-size:13px">
                    <div><strong>{{ s.count }}</strong> — {{ s.reason }}</div>
                    <div class="text-muted mono" style="font-size:12px">
                        {{ s.symbols.join(', ') }}<span v-if="s.count > s.symbols.length"> …</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Picker -->
        <div v-else class="d-flex flex-column gap-3">
            <div class="p-3 rounded" style="background:#f8fafc;font-size:13px;color:#475467;border:1px solid #E7EBF1">
                <p class="mb-2">Download it from NSE: <strong>Issue Information → Past Issues → Download (CSV)</strong>. Only this format is accepted.</p>
                <ul class="mb-0 ps-3">
                    <li>IPOs <strong>not already in the list</strong> are added (symbol, company name, listing date).</li>
                    <li>Ones already there are left exactly as they are.</li>
                    <li>No listing date in the file? <strong>Issue end date + 3 days</strong> is used, and you will see which rows that applied to.</li>
                    <li>Rows with an implausible listing date (partly-paid shares, old FPOs) are skipped — you will see which.</li>
                </ul>
            </div>

            <div>
                <label class="form-label mb-1" style="font-size:13px">Ignore IPOs listed before</label>
                <input type="date" class="form-control form-control-sm" v-model="from" style="max-width:180px" />
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
                <div v-else class="mt-2 text-muted">Click or drop the NSE .csv file here</div>
            </div>
            <input ref="fileInput" type="file" accept=".csv" class="d-none" @change="onPicked" />
        </div>

        <template #footer>
            <div class="d-flex gap-2">
                <button v-if="!result" type="button" class="aside-btn-primary" :disabled="loading || !file" @click="upload">
                    {{ loading ? 'Uploading…' : 'Upload & Add' }}
                </button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">
                    {{ result ? 'Done' : 'Cancel' }}
                </button>
            </div>
        </template>
    </Aside>
</template>
