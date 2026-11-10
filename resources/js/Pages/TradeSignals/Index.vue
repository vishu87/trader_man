<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtPct, fmtDate, pnlClass } from '@/Service/Utils/Format.js';
import { paperChip, paperLabel, entryPct, flagText, entryNote, validText, statsLine } from '@/Service/Utils/Trade.js';
import ChartPopup from '@/Includes/Charts/ChartPopup.vue';

// Every stock that is in a paper trade right now — an entry waiting to fill, or a filled position — with where the price stands between
// its stop loss and target. These are the paper trades made from Claude / OpenAI analyses; no real order exists anywhere.
const loading  = ref(false);
const active   = ref([]);
const history  = ref([]);
const summary  = ref({ open: 0, pending: 0, invested: 0, at_risk: 0, unrealised: 0, alerts: 0 });
const stats    = ref(null);
const ltpError = ref(null);
const loaded   = ref(false);

const tab    = ref('active');     // 'active' | 'history'
const search = ref('');

function fetchData(refresh = false) {
    loading.value = true;
    return DBService.postData('/api/trade-signals/list', { refresh: refresh ? 1 : 0 }).then((data) => {
        loading.value = false;
        loaded.value = true;
        if (!data.success) { alert(data.message); return; }
        active.value   = data.active;
        history.value  = data.history;
        summary.value  = data.summary;
        stats.value    = data.stats;
        ltpError.value = data.ltp_error;
    });
}

onMounted(() => fetchData());

const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (tab.value === 'active' ? active.value : history.value).filter(r => !q || r.symbol.toLowerCase().includes(q));
});

// ─── Chart popup: step through the rows as listed ───
const showChartPopup = ref(false);
const popupItems     = ref([]);
const popupStart     = ref(0);

function openChart(row) {
    popupItems.value = rows.value.map(r => ({ exchange: r.exchange, symbol: r.symbol }));
    popupStart.value = Math.max(0, rows.value.findIndex(r => r.id === row.id));
    showChartPopup.value = true;
}

const money = (v) => (v < 0 ? '−' : v > 0 ? '+' : '') + '₹' + fmtNum(Math.abs(v), 0);
</script>

<template>
    <PageHeader
        title="Trade Signals"
        subtitle="Paper trades from AI buy signals — entry, stop loss, target and where the price is now"
        placeholder="Search symbol…"
        @search="(q) => search = q"
    >
        <template #actions>
            <button class="btn-secondary-sm" :disabled="loading" @click="fetchData(true)">
                <span v-if="loading" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-arrow-clockwise"></i>
                Refresh LTP
            </button>
        </template>
    </PageHeader>

    <div class="page-canvas">

        <div v-if="ltpError" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Live prices unavailable — using the last stored close. {{ ltpError }}
        </div>
        <div v-if="summary.alerts" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ summary.alerts }} open trade{{ summary.alerts === 1 ? ' is' : 's are' }} already through the stop loss or target at the live price.
        </div>

        <!-- Summary -->
        <div class="pf-summary">
            <div class="pf-stat">
                <div class="pf-stat-label">Active signals</div>
                <div class="pf-stat-value">{{ summary.open }} open</div>
                <div class="pf-stat-sub">{{ summary.pending }} waiting for entry</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Capital in open signals</div>
                <div class="pf-stat-value">₹ {{ fmtNum(summary.invested) }}</div>
                <div class="pf-stat-sub">at the filled prices</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Risk if all stops hit</div>
                <div class="pf-stat-value pnl-neg">₹ {{ fmtNum(summary.at_risk) }}</div>
                <div class="pf-stat-sub">(fill − stop) × quantity</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Unrealised P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(summary.unrealised)">{{ money(summary.unrealised) }}</div>
                <div class="pf-stat-sub">open signals, at the live price</div>
            </div>
        </div>

        <div class="card-panel">

            <div class="pf-tabs">
                <button class="pf-tab" :class="{ active: tab === 'active' }" @click="tab = 'active'">
                    Active <span class="pf-tab-count">{{ active.length }}</span>
                </button>
                <button class="pf-tab" :class="{ active: tab === 'history' }" @click="tab = 'history'">
                    Closed <span class="pf-tab-count">{{ history.length }}</span>
                </button>
                <span v-if="stats" class="ms-auto me-3 align-self-center text-muted" style="font-size:12.5px">{{ statsLine(stats) }}</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width:130px">Symbol</th>
                            <th>Status</th>
                            <th class="text-end">Entry</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Stop loss</th>
                            <th class="text-end">Target</th>
                            <th v-if="tab === 'active'" class="text-end">LTP</th>
                            <th class="text-end">{{ tab === 'active' ? 'P&L' : 'Result' }}</th>
                            <th v-if="tab === 'active'" style="min-width:170px" title="Stop loss on the left, target on the right. The grey tick is the entry, the blue dot is the price now.">Stop → Target</th>
                            <th style="min-width:180px">Signal</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!loaded">
                            <td :colspan="tab === 'active' ? 11 : 9" class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td :colspan="tab === 'active' ? 11 : 9" class="text-center py-5 text-muted">
                                <template v-if="search">No signal trades match your search</template>
                                <template v-else-if="tab === 'active'">No active signal trades. A BUY call from the Trade panel in a chart opens one here.</template>
                                <template v-else>No closed signal trades yet.</template>
                            </td>
                        </tr>
                        <template v-else>
                            <tr v-for="r in rows" :key="r.id" :class="{ 'trade-alert': r.live_flag }">
                                <td>
                                    <button class="btn btn-link p-0 cell-primary mono text-start" title="Open the charts" style="text-decoration:none" @click="openChart(r)">
                                        <i v-if="r.followed" class="bi bi-star-fill me-1" style="color:#F5A623;font-size:11px"></i>{{ r.symbol }}
                                    </button>
                                    <div class="cell-secondary mono" :class="{ 'text-primary': r.exchange !== 'NSE' }">{{ r.exchange }}</div>
                                </td>
                                <td>
                                    <span class="status-chip" :class="paperChip(r.status)">{{ paperLabel(r.status) }}</span>
                                    <div v-if="r.status === 'pending'" class="cell-secondary" :title="validText(r)">{{ r.entry_type === 'stop' ? 'buy-stop' : 'buy-limit' }}</div>
                                </td>
                                <td class="text-num">
                                    {{ fmtPrice(r.fill_price ?? r.entry_price) }}
                                    <div v-if="r.fill_price" class="cell-secondary" style="margin:0">{{ fmtDate(r.filled_on) }}</div>
                                    <div v-else-if="tab === 'active'" class="cell-secondary" style="margin:0;white-space:normal;max-width:190px">{{ entryNote(r) }}</div>
                                </td>
                                <td class="text-num">{{ fmtNum(r.qty) }}</td>
                                <td class="text-num pnl-neg">
                                    {{ fmtPrice(r.stop_loss) }}
                                    <div v-if="tab === 'active' && r.to_stop_pct !== null" class="cell-secondary" style="margin:0">{{ fmtNum(r.to_stop_pct, 1) }}% away</div>
                                </td>
                                <td class="text-num pnl-pos">
                                    {{ fmtPrice(r.target_price) }}
                                    <div v-if="tab === 'active' && r.to_target_pct !== null" class="cell-secondary" style="margin:0">{{ fmtNum(r.to_target_pct, 1) }}% away</div>
                                </td>

                                <td v-if="tab === 'active'" class="text-num">
                                    <span v-if="r.ltp < 0" class="status-chip chip-red" :title="`No LTP for ${r.exchange}:${r.symbol}`">-1</span>
                                    <template v-else>{{ fmtPrice(r.ltp) }}</template>
                                </td>

                                <td class="text-num">
                                    <template v-if="tab === 'active'">
                                        <template v-if="r.pnl !== null">
                                            <span :class="pnlClass(r.pnl)">{{ money(r.pnl) }}</span>
                                            <div class="cell-secondary" :class="pnlClass(r.pnl_pct)" style="margin:0">{{ fmtPct(r.pnl_pct) }}<template v-if="r.r_now !== null"> · {{ r.r_now > 0 ? '+' : '' }}{{ r.r_now }}R</template></div>
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </template>
                                    <template v-else>
                                        <template v-if="r.pnl !== null">
                                            <span :class="pnlClass(r.pnl)">{{ money(r.pnl) }}</span>
                                            <div class="cell-secondary" style="margin:0"><template v-if="r.r_multiple !== null">{{ r.r_multiple > 0 ? '+' : '' }}{{ r.r_multiple }}R · </template>{{ fmtDate(r.closed_on) }}</div>
                                        </template>
                                        <span v-else class="text-muted" title="The entry was never reached — no trade took place">no fill</span>
                                    </template>
                                </td>

                                <td v-if="tab === 'active'">
                                    <template v-if="r.progress !== null">
                                        <div class="trade-bar" :title="`Price is ${r.progress}% of the way from the stop to the target`">
                                            <span class="trade-bar-entry" :style="{ left: entryPct(r) + '%' }"></span>
                                            <span class="trade-bar-now" :style="{ left: r.progress + '%' }"></span>
                                        </div>
                                        <div class="trade-bar-legend"><span>SL</span><span>TP</span></div>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                    <div v-if="r.live_flag" class="status-chip chip-red mt-1" style="white-space:normal" :title="flagText(r)">
                                        {{ r.live_flag === 'below_stop' ? 'Through the stop' : 'Through the target' }}
                                    </div>
                                </td>

                                <td style="font-size:12.5px">
                                    <div>{{ r.pattern || '—' }}</div>
                                    <div class="cell-secondary" style="margin:0"><template v-if="r.confidence !== null">{{ r.confidence }}% · </template>{{ fmtDate(r.signal_date) }}</div>
                                </td>

                                <td class="text-end text-nowrap">
                                    <button class="tbl-icon-btn me-1" title="Open charts (weekly + monthly) with the Trade panel" @click="openChart(r)"><i class="bi bi-bar-chart-line"></i></button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <ChartPopup :show="showChartPopup" :items="popupItems" :start-index="popupStart" @close="showChartPopup = false" />
</template>
