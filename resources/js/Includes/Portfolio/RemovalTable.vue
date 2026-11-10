<script setup>
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum } from '@/Service/Utils/Format.js';

defineProps({
    rows:    { type: Array,   default: () => [] },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

function clear(r) {
    if (!confirm(`Clear the removal entry for ${r.symbol}?\n\nDo this once you have fixed the quantity in the buckets.`)) return;
    DBService.postData('/api/portfolio/removal/delete', { id: r.id }).then((data) => {
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
                    <th class="text-end">Qty To Remove</th>
                    <th class="text-end">CSV Qty</th>
                    <th class="text-end">In Buckets</th>
                    <th>Held In</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="6" class="text-center py-5 text-muted">
                        <span class="spinner-border spinner-border-sm me-2"></span> Loading…
                    </td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td colspan="6" class="text-center py-5 text-muted">Nothing to remove</td>
                </tr>
                <template v-else>
                    <tr v-for="r in rows" :key="r.id">
                        <td><span class="cell-primary mono">{{ r.symbol }}</span></td>
                        <td class="text-num fw-semibold pnl-neg">{{ fmtNum(r.quantity) }}</td>
                        <td class="text-num text-muted">{{ fmtNum(r.uploaded_qty) }}</td>
                        <td class="text-num text-muted">{{ fmtNum(r.bucket_qty) }}</td>
                        <td style="font-size:12.5px">
                            <span v-for="(h, i) in r.held_in" :key="i">
                                {{ h.bucket_name }} <span class="text-muted mono">({{ fmtNum(h.quantity) }})</span><span v-if="i < r.held_in.length - 1">, </span>
                            </span>
                            <span v-if="!r.held_in.length" class="text-muted">—</span>
                        </td>
                        <td class="text-end">
                            <button class="tbl-icon-btn tbl-icon-btn-danger" title="Clear entry" @click="clear(r)">
                                <i class="bi bi-check2"></i>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
