<script setup>
import { ref, computed, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';
import { decorate } from '@/Service/Utils/Watchlist.js';
import { fmtNum, fmtPrice, fmtPct } from '@/Service/Utils/Format.js';
import { actionChip, paperChip, paperLabel, paperText, signalSizing, holdingPnlPct, statsLine } from '@/Service/Utils/Trade.js';

// The Trade panel of the chart popup: the AI's call on this stock, your own buy / stop plan with the position size, and the
// paper-trade record. Everything here is advice and simulation — no real order is placed from this panel.
const props = defineProps({
    item: { type: Object, required: true },   // { exchange, symbol }
});

const state     = ref(null);     // /api/trade/state
const loading   = ref(true);
const analysing = ref(false);
const message   = ref('');       // an error or note shown under the analyse button
const shown     = ref(null);     // the signal on display: the latest one, or the one just made
const paper     = ref(null);     // this stock's latest paper trade
const planBuy   = ref('');
const planStop  = ref('');
const planBusy  = ref(false);

const body = () => ({ symbol: props.item.symbol, exchange: props.item.exchange });
const toNum = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? null : Number(v);

// quiet = refresh the numbers without blanking the panel or overwriting a plan the user may be typing
function load(quiet = false) {
    if (!quiet) loading.value = true;

    DBService.postData('/api/trade/state', body()).then((data) => {
        loading.value = false;
        if (!data.success) { message.value = data.message; return; }

        state.value = data;
        shown.value = data.signals[0] || null;
        paper.value = data.paper_trade;
        if (!quiet) {
            planBuy.value  = data.plan && data.plan.buy_price !== null ? data.plan.buy_price : '';
            planStop.value = data.plan && data.plan.stop_loss !== null ? data.plan.stop_loss : '';
        }
    });
}

onMounted(() => load());

// ─── Ask Claude ──────────────────────────────────────────────────────

function analyse(force = false) {
    analysing.value = true;
    message.value = '';

    DBService.postData('/api/trade/analyse', { ...body(), force: force ? 1 : 0 }).then((data) => {
        analysing.value = false;
        if (!data.success) { message.value = data.message; return; }

        shown.value = data.signal;
        if (data.paper_trade) paper.value = data.paper_trade;
        message.value = data.paper_note || (data.reused ? data.message : '');
        load(true);   // spend-today, scoreboard
    });
}

// ─── Your plan ───────────────────────────────────────────────────────

function savePlan(buy, stop) {
    planBusy.value = true;
    DBService.postData('/api/trade/plan', { ...body(), buy_price: buy, stop_loss: stop }).then((data) => {
        planBusy.value = false;
        if (!data.success) { alert(data.message); return; }
        planBuy.value  = data.plan.buy_price ?? '';
        planStop.value = data.plan.stop_loss ?? '';
        state.value.plan = data.plan;
    });
}

const useSignalAsPlan = () => savePlan(shown.value.entry_price, shown.value.stop_loss);

// ─── Derived for display ─────────────────────────────────────────────

const ltp           = computed(() => state.value ? state.value.ltp : -1);
const signalSize    = computed(() => state.value ? signalSizing(shown.value, ltp.value, state.value.max_loss) : null);
const planSize      = computed(() => state.value ? decorate({ ltp: ltp.value, buy_price: toNum(planBuy.value), stop_loss: toNum(planStop.value) }, state.value.max_loss) : null);
const holdingPnl    = computed(() => state.value ? holdingPnlPct(state.value.holding, ltp.value) : null);
const canSavePlan   = computed(() => state.value && state.value.plan !== null);
</script>

<template>
    <aside class="trade-panel">

        <div v-if="loading" class="text-center text-muted py-5">
            <span class="spinner-border spinner-border-sm me-2"></span> Loading…
        </div>

        <template v-else-if="state">

            <!-- Claude's analysis -->
            <section class="trade-card">
                <div class="d-flex align-items-center mb-2">
                    <h6 class="trade-title mb-0">{{ state.provider }}'s analysis</h6>
                    <span class="ms-auto cell-secondary" style="margin:0" :title="`Analyses made today, out of the daily limit · model ${state.model}`">{{ state.analyses_today }} / {{ state.max_per_day }} today</span>
                </div>

                <div v-if="!state.api_key_set" class="trade-note warn">
                    Add <code>{{ state.key_name }}</code> to <code>.env</code> to turn this on.
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-primary" :disabled="analysing || !state.api_key_set" @click="analyse(false)">
                        <span v-if="analysing" class="spinner-border spinner-border-sm me-1"></span>
                        {{ analysing ? 'Analysing…' : (shown ? 'Analyse again' : 'Analyse with ' + state.provider) }}
                    </button>
                    <button v-if="shown && !analysing" class="btn btn-sm btn-outline-secondary" title="Ignore the recent answer and ask again" @click="analyse(true)">
                        <i class="bi bi-arrow-repeat"></i> Force
                    </button>
                </div>
                <p v-if="analysing" class="text-muted mb-0 mt-2" style="font-size:12px">{{ state.provider }} is reading the monthly, weekly and daily charts — this can take up to a minute.</p>
                <div v-if="message" class="trade-note" :class="{ warn: !message.startsWith('Analysed') }">{{ message }}</div>

                <!-- the call -->
                <div v-if="shown" class="mt-3">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="status-chip" :class="actionChip(shown.action)" style="font-size:13px;text-transform:uppercase">{{ shown.action }}</span>
                        <span class="text-muted" style="font-size:12.5px">{{ shown.confidence }}% confidence</span>
                        <span class="ms-auto cell-secondary" style="margin:0" :title="shown.created_at + ' · ' + shown.model">{{ shown.ago }}</span>
                    </div>
                    <div v-if="shown.pattern" class="fw-semibold mt-2" style="font-size:13px">{{ shown.pattern }}</div>
                    <p v-if="shown.summary" class="mt-1 mb-2" style="font-size:13px">{{ shown.summary }}</p>

                    <!-- the risk rules overruled Claude -->
                    <div v-if="shown.risk_note" class="trade-note warn">{{ shown.risk_note }}</div>

                    <!-- entry / stop / target + size -->
                    <table v-if="shown.entry_price !== null" class="trade-kv">
                        <tbody>
                            <tr><td>Entry</td><td class="mono">{{ fmtPrice(shown.entry_price) }}</td><td class="text-muted">{{ shown.entry_price > shown.ltp ? 'breakout' : 'pullback' }}, valid {{ shown.valid_days }} sessions</td></tr>
                            <tr><td>Stop loss</td><td class="mono pnl-neg">{{ fmtPrice(shown.stop_loss) }}</td><td class="text-muted">{{ signalSize ? signalSize.risk_pct + '% below entry' : '' }}</td></tr>
                            <tr><td>Target</td><td class="mono pnl-pos">{{ fmtPrice(shown.target_price) }}</td><td class="text-muted">reward : risk {{ shown.reward_risk }}</td></tr>
                            <tr v-if="signalSize && signalSize.qty !== null"><td>Quantity</td><td class="mono fw-semibold">{{ fmtNum(signalSize.qty) }}</td><td class="text-muted">capital ₹{{ fmtNum(signalSize.capital) }} · loss at stop ₹{{ fmtNum(signalSize.loss_at_sl) }}</td></tr>
                        </tbody>
                    </table>

                    <div v-if="shown.entry_price !== null && canSavePlan" class="mt-2">
                        <button class="btn btn-sm btn-outline-primary" :disabled="planBusy" title="Copy the analysis' entry and stop loss onto this stock's watchlist entry" @click="useSignalAsPlan">
                            Use as my plan
                        </button>
                    </div>

                    <template v-if="shown.reasons.length">
                        <div class="trade-sub">Why</div>
                        <ul class="trade-list"><li v-for="(r, i) in shown.reasons" :key="i">{{ r }}</li></ul>
                    </template>
                    <template v-if="shown.risks.length">
                        <div class="trade-sub">Risks</div>
                        <ul class="trade-list"><li v-for="(r, i) in shown.risks" :key="i">{{ r }}</li></ul>
                    </template>
                    <template v-if="shown.invalidation">
                        <div class="trade-sub">Wrong if</div>
                        <p class="mb-0" style="font-size:12.5px">{{ shown.invalidation }}</p>
                    </template>
                </div>
            </section>

            <!-- paper trade -->
            <section class="trade-card">
                <div class="d-flex align-items-center mb-2">
                    <h6 class="trade-title mb-0">Paper trade</h6>
                    <span v-if="paper" class="status-chip ms-auto" :class="paperChip(paper.status)">{{ paperLabel(paper.status) }}</span>
                </div>
                <p v-if="paper" class="mb-2" style="font-size:12.5px">{{ paperText(paper) }}</p>
                <p v-else class="text-muted mb-2" style="font-size:12.5px">Every valid BUY call opens a simulated trade here. No real order is ever placed.</p>
                <div class="trade-note" style="margin:0">
                    <strong>Record so far:</strong> {{ statsLine(state.stats) }}
                </div>
            </section>

            <!-- your own plan -->
            <section class="trade-card">
                <h6 class="trade-title">Your plan</h6>

                <div v-if="state.holding" class="mb-2" style="font-size:12.5px">
                    You hold <strong>{{ fmtNum(state.holding.quantity) }}</strong> at avg {{ fmtPrice(state.holding.avg_price) }}
                    <span v-if="holdingPnl !== null" :class="holdingPnl >= 0 ? 'pnl-pos' : 'pnl-neg'">({{ fmtPct(holdingPnl) }})</span>
                    <div class="text-muted">{{ state.holding.buckets.join(', ') }}</div>
                </div>

                <template v-if="canSavePlan">
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label mb-1" style="font-size:12px">Buy price</label>
                            <input v-model="planBuy" type="number" step="any" min="0" class="form-control form-control-sm" placeholder="—" />
                        </div>
                        <div class="col-6">
                            <label class="form-label mb-1" style="font-size:12px">Stop loss</label>
                            <input v-model="planStop" type="number" step="any" min="0" class="form-control form-control-sm" placeholder="—" />
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary mb-2" :disabled="planBusy" @click="savePlan(planBuy, planStop)">
                        {{ planBusy ? 'Saving…' : 'Save plan' }}
                    </button>

                    <table v-if="planSize && planSize.qty !== null" class="trade-kv">
                        <tbody>
                            <tr><td>Risk / share</td><td class="mono">{{ fmtPrice(planSize.risk_per_share) }}</td><td class="text-muted">{{ planSize.risk_pct }}% of the buy price</td></tr>
                            <tr><td>Quantity</td><td class="mono fw-semibold">{{ fmtNum(planSize.qty) }}</td><td class="text-muted">so a stop-out costs ≤ ₹{{ fmtNum(state.max_loss) }}</td></tr>
                            <tr><td>Capital</td><td class="mono">₹{{ fmtNum(planSize.capital) }}</td><td></td></tr>
                            <tr><td>Loss at stop</td><td class="mono pnl-neg">₹{{ fmtNum(planSize.loss_at_sl) }}</td><td></td></tr>
                        </tbody>
                    </table>
                    <p v-else class="text-muted mb-0" style="font-size:12px">Set a buy price and a stop loss below it to see the position size for your ₹{{ fmtNum(state.max_loss) }} max loss.</p>
                </template>
                <p v-else class="text-muted mb-0" style="font-size:12.5px">Add this stock to a watchlist to keep a buy price and stop loss for it.</p>
            </section>

        </template>

        <div v-else-if="message" class="trade-note warn">{{ message }}</div>

    </aside>
</template>
