<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum, fmtPrice, fmtPct, pnlClass } from '@/Service/Utils/Format.js';

const loading   = ref(false);
const buckets   = ref([]);
const holdings  = ref([]);
const notMapped = ref([]);
const removals  = ref([]);
const ltpError  = ref(null);

const tokenBusy = ref(false);
const tokenMsg  = ref('');

function fetchData(refresh = false) {
    loading.value = true;
    DBService.postData('/api/portfolio/data', { refresh: refresh ? 1 : 0 }).then((data) => {
        if (data.success) {
            buckets.value   = data.buckets;
            holdings.value  = data.holdings;
            notMapped.value = data.not_mapped;
            removals.value  = data.removals;
            ltpError.value  = data.ltp_error;
        } else {
            alert(data.message);
        }
        loading.value = false;
    });
}

onMounted(fetchData);

// Calls the online token link; the server stores whatever it returns in the settings table
function refreshToken() {
    tokenBusy.value = true;
    tokenMsg.value  = '';
    DBService.postData('/api/dashboard/refresh-token', {}).then((data) => {
        tokenBusy.value = false;
        if (!data.success) {
            alert(data.message);
            return;
        }
        tokenMsg.value = data.message;
        setTimeout(() => { tokenMsg.value = ''; }, 8000);
        fetchData(true);   // prices that were failing for lack of a token can load now
    });
}

// ─── Totals ──────────────────────────────────────────────────────────
// Same rules as the Portfolio page: tracking stocks are never counted, and a stock without an LTP
// counts toward Invested but is left out of Current Value / P&L.

function totals(list) {
    const priced   = list.filter(h => h.current_value !== null);
    const invested = list.reduce((s, h) => s + h.invested, 0);
    const pricedInvested = priced.reduce((s, h) => s + h.invested, 0);
    const current  = priced.reduce((s, h) => s + h.current_value, 0);
    const pnl      = current - pricedInvested;

    return {
        count: list.length,
        noLtp: list.length - priced.length,
        invested,
        current,
        pnl,
        pnlPct: pricedInvested > 0 ? pnl / pricedInvested * 100 : null,
    };
}

const excludedIds = computed(() => new Set(buckets.value.filter(b => b.exclude_from_all).map(b => b.id)));

// the overall portfolio = owned stocks in buckets that are part of "All"
const overallRows = computed(() => holdings.value.filter(h => !h.is_tracking && !excludedIds.value.has(h.bucket_id)));
const overall     = computed(() => totals(overallRows.value));

const bucketRows = computed(() =>
    buckets.value.map(b => ({
        ...b,
        ...totals(holdings.value.filter(h => h.bucket_id === b.id && !h.is_tracking)),
        tracking: holdings.value.filter(h => h.bucket_id === b.id && h.is_tracking).length,
    }))
);

// biggest movers by absolute P&L (₹), among stocks that have a price
const movers = computed(() => overallRows.value.filter(h => h.pnl !== null && h.quantity > 0));
const gainers = computed(() => [...movers.value].filter(h => h.pnl > 0).sort((a, b) => b.pnl - a.pnl).slice(0, 5));
const losers  = computed(() => [...movers.value].filter(h => h.pnl < 0).sort((a, b) => a.pnl - b.pnl).slice(0, 5));

// ─── Needs attention ─────────────────────────────────────────────────
const unchecked = computed(() => overallRows.value.filter(h => !h.checked).length);
const portfolioUrl = computed(() => base_url + '/portfolio');
</script>

<template>
    <PageHeader title="Dashboard" subtitle="Portfolio overview" :show-search="false">
        <template #actions>
            <button class="btn-secondary-sm" :disabled="tokenBusy" title="Fetch today's Kite access token and save it" @click="refreshToken">
                <span v-if="tokenBusy" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-key"></i>
                Refresh Token
            </button>
            <button class="btn-secondary-sm" :disabled="loading" @click="fetchData(true)">
                <span v-if="loading" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-arrow-clockwise"></i>
                Refresh LTP
            </button>
            <a class="btn-new-filing text-decoration-none" :href="portfolioUrl">
                <i class="bi bi-briefcase me-1"></i> Open Portfolio
            </a>
        </template>
    </PageHeader>

    <Content>

        <div v-if="tokenMsg" class="pf-alert pf-alert-ok">
            <i class="bi bi-check-circle-fill"></i> {{ tokenMsg }}
        </div>

        <div v-if="ltpError" class="pf-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            LTP unavailable — showing -1. {{ ltpError }}
        </div>

        <!-- Overall portfolio -->
        <div class="pf-summary">
            <div class="pf-stat">
                <div class="pf-stat-label">Invested</div>
                <div class="pf-stat-value">₹ {{ fmtNum(overall.invested, 0) }}</div>
                <div class="pf-stat-sub">{{ overall.count }} stock{{ overall.count === 1 ? '' : 's' }} · {{ buckets.length }} bucket{{ buckets.length === 1 ? '' : 's' }}</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">Current Value</div>
                <div class="pf-stat-value">₹ {{ fmtNum(overall.current, 0) }}</div>
                <div v-if="overall.noLtp" class="pf-stat-sub pnl-neg">{{ overall.noLtp }} without LTP (excluded)</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">P&amp;L</div>
                <div class="pf-stat-value" :class="pnlClass(overall.pnl)">{{ overall.pnl > 0 ? '+' : '' }}₹ {{ fmtNum(overall.pnl, 0) }}</div>
            </div>
            <div class="pf-stat">
                <div class="pf-stat-label">P&amp;L %</div>
                <div class="pf-stat-value" :class="pnlClass(overall.pnlPct)">{{ fmtPct(overall.pnlPct) }}</div>
            </div>
        </div>

        <!-- Needs attention -->
        <div class="pf-summary">
            <a class="pf-stat text-decoration-none" :href="portfolioUrl">
                <div class="pf-stat-label">Not Checked</div>
                <div class="pf-stat-value">{{ unchecked }}</div>
                <div class="pf-stat-sub">of {{ overall.count }} stocks</div>
            </a>
            <a class="pf-stat text-decoration-none" :href="portfolioUrl">
                <div class="pf-stat-label">Not Mapped</div>
                <div class="pf-stat-value" :class="{ 'text-warning': notMapped.length }">{{ notMapped.length }}</div>
                <div class="pf-stat-sub">CSV quantity not in any bucket</div>
            </a>
            <a class="pf-stat text-decoration-none" :href="portfolioUrl">
                <div class="pf-stat-label">Removal</div>
                <div class="pf-stat-value" :class="{ 'pnl-neg': removals.length }">{{ removals.length }}</div>
                <div class="pf-stat-sub">Bucket quantity not in CSV</div>
            </a>
            <a class="pf-stat text-decoration-none" :href="portfolioUrl">
                <div class="pf-stat-label">Without LTP</div>
                <div class="pf-stat-value" :class="{ 'pnl-neg': overall.noLtp }">{{ overall.noLtp }}</div>
                <div class="pf-stat-sub">showing -1</div>
            </a>
        </div>

        <!-- Buckets -->
        <div class="card-panel mb-3">
            <div class="d-flex align-items-center m-3">
                <span class="fw-semibold">Buckets</span>
                <span class="text-muted ms-2" style="font-size:12px">tracking stocks not counted</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bucket</th>
                            <th class="text-end">Stocks</th>
                            <th class="text-end">Invested</th>
                            <th class="text-end">Current Value</th>
                            <th class="text-end">P&amp;L</th>
                            <th class="text-end">P&amp;L %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading && !buckets.length">
                            <td colspan="6" class="text-center py-5 text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading…
                            </td>
                        </tr>
                        <tr v-else-if="!bucketRows.length">
                            <td colspan="6" class="text-center py-5 text-muted">No buckets yet — create one in Portfolio</td>
                        </tr>
                        <template v-else>
                            <tr v-for="b in bucketRows" :key="b.id">
                                <td>
                                    <span class="cell-primary">{{ b.name }}</span>
                                    <i v-if="b.exclude_from_all" class="bi bi-eye-slash ms-2 text-muted" title="Excluded from the overall totals above" style="font-size:12px"></i>
                                    <div v-if="b.tracking" class="cell-secondary">{{ b.tracking }} tracking</div>
                                </td>
                                <td class="text-num">{{ fmtNum(b.count) }}</td>
                                <td class="text-num">{{ fmtPrice(b.invested) }}</td>
                                <td class="text-num">{{ fmtPrice(b.current) }}</td>
                                <td class="text-num" :class="pnlClass(b.pnl)">{{ fmtPrice(b.pnl) }}</td>
                                <td class="text-num">
                                    <span v-if="b.pnlPct !== null" class="pnl-badge" :class="pnlClass(b.pnlPct)">{{ fmtPct(b.pnlPct) }}</span>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top gainers / losers -->
        <div class="row g-3">
            <div v-for="block in [{ title: 'Top Gainers', rows: gainers }, { title: 'Top Losers', rows: losers }]" :key="block.title" class="col-lg-6">
                <div class="card-panel">
                    <div class="fw-semibold m-3">{{ block.title }} <span class="text-muted" style="font-size:12px">by P&amp;L ₹</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th class="text-end">P&amp;L</th>
                                    <th class="text-end">P&amp;L %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!block.rows.length">
                                    <td colspan="3" class="text-center py-4 text-muted">{{ loading ? 'Loading…' : 'Nothing to show' }}</td>
                                </tr>
                                <tr v-for="h in block.rows" :key="h.id">
                                    <td>
                                        <span class="cell-primary mono">{{ h.symbol }}</span>
                                        <div class="cell-secondary">{{ h.bucket_name }}</div>
                                    </td>
                                    <td class="text-num" :class="pnlClass(h.pnl)">{{ fmtPrice(h.pnl) }}</td>
                                    <td class="text-num">
                                        <span v-if="h.pnl_pct !== null" class="pnl-badge" :class="pnlClass(h.pnl_pct)">{{ fmtPct(h.pnl_pct) }}</span>
                                        <template v-else>—</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </Content>
</template>
