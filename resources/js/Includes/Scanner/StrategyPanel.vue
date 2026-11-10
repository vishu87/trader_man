<script setup>
import { computed } from 'vue';

// The rules a backtest is run with. Plain controls (it is a calculator, nothing here is saved).
const params  = defineModel('params', { type: Object, required: true });
const props   = defineProps({
    presets: { type: Array, default: () => [] },
    busy:    { type: Boolean, default: false },
});
const emit = defineEmits(['run']);

const RULE_RESET = { strategy: 'rules', first_amount: 20000, add_amount: 0, max_adds: 3, add_drop_pct: 3, entry_price: 'close', hold_days: 0, exit_at: 'close', stop_pct: '', target_pct: '' };

function applyPreset(e) {
    const p = props.presets.find(x => x.id === e.target.value);
    if (!p) return;
    Object.assign(params.value, RULE_RESET, p.p);
    if (params.value.stop_pct === null) params.value.stop_pct = '';
    if (params.value.target_pct === null) params.value.target_pct = '';
    e.target.value = '';
    emit('run');
}

const CAPS = ['Largecap', 'Midcap', 'Smallcap'];
function toggleCap(c) {
    const i = params.value.caps.indexOf(c);
    if (i >= 0) params.value.caps.splice(i, 1); else params.value.caps.push(c);
}
const holdsOvernight = computed(() => Number(params.value.hold_days) >= 1);
</script>

<template>
    <div class="card-panel sc-strategy">
        <div class="sc-strategy-head">
            <h6 class="mb-0">Rules</h6>
            <select class="form-select form-select-sm sc-preset" @change="applyPreset">
                <option value="">Load a preset…</option>
                <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select v-model="params.strategy" class="form-select form-select-sm sc-preset" title="Which exits and averaging to replay">
                <option value="manual">Manual: what we did by hand</option>
                <option value="rules">Rules: SMA / VWAP / hold days</option>
            </select>
            <button class="btn-new-filing ms-auto" :disabled="busy" @click="emit('run')">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-play-fill me-1"></i> Run
            </button>
        </div>

        <div class="sc-groups">
            <fieldset class="sc-group">
                <legend>Entry</legend>
                <label class="sc-field"><span>First buy ₹</span><input v-model.number="params.first_amount" type="number" min="0" step="500" class="form-control form-control-sm" /></label>
                <label v-if="params.strategy === 'rules'" class="sc-field" title="Money for each extra buy (0 = never average). The 1st extra buy is when the price falls to the 5-minute 20 SMA; later ones are each a set % under the last buy"><span>Average with ₹</span><input v-model.number="params.add_amount" type="number" min="0" step="500" class="form-control form-control-sm" /></label>
                <label v-if="params.strategy === 'rules'" class="sc-field"><span>Max extra buys</span><input v-model.number="params.max_adds" type="number" min="0" max="50" class="form-control form-control-sm" /></label>
                <label v-if="params.strategy === 'rules'" class="sc-field" title="After the 20 SMA buy, buy again each time the price is this % under the last buy"><span>…then every % lower</span><input v-model.number="params.add_drop_pct" type="number" min="0.1" max="50" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Use the Signal quality tab's checks (and its thresholds) to decide which signals to trade"><span>Signal filter</span>
                    <select v-model="params.quality_mode" class="form-select form-select-sm">
                        <option value="off">off: every signal</option>
                        <option value="enter">only "Enter now" signals</option>
                        <option value="wait">"Enter now" + wait for the entry</option>
                    </select>
                </label>
                <label class="sc-check sc-field" title="Trade only after the signal's 5-minute candle has closed, and only if it closed green (close above open)"><input v-model="params.green_candle" type="checkbox" /> Green candle only</label>
                <label v-if="params.strategy === 'rules'" class="sc-check sc-field" title="An extra buy only while the price is under the day's VWAP"><input v-model="params.add_below_vwap" type="checkbox" /> Average only below VWAP</label>
                <label class="sc-field" title="The scanner fires when a 5-minute candle closes"><span>Buy at</span>
                    <select v-model="params.entry_price" class="form-select form-select-sm"><option value="close">signal candle close</option><option value="next_open">next candle open</option></select>
                </label>
                <label class="sc-field" title="Adverse move on every fill, % — thin stocks do not fill at the print"><span>Slippage %</span><input v-model.number="params.slippage_pct" type="number" min="0" step="0.05" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Signals from</span><input v-model="params.entry_from" type="time" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>…until</span><input v-model="params.entry_until" type="time" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="1 = trade the first signal of the day; 2 = wait for the stock to be flagged again"><span>Act on signal #</span><input v-model.number="params.after_signals" type="number" min="1" max="100" class="form-control form-control-sm" /></label>
            </fieldset>

            <fieldset v-if="params.strategy === 'manual'" class="sc-group">
                <legend>Manual strategy</legend>
                <label class="sc-field" title="Money for each extra buy (0 = never average)"><span>Average with ₹</span><input v-model.number="params.m_add_amount" type="number" min="0" step="500" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Extra buys at 1, 2, 3 steps under the FIRST buy price"><span>Extra buys</span><input v-model.number="params.m_max_adds" type="number" min="0" max="10" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Each extra buy is this many % further under the FIRST buy price (5 → −5 %, −10 %, −15 %)"><span>…every % lower</span><input v-model.number="params.m_add_step_pct" type="number" min="0.5" max="50" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Sell everything when the price is this % under the average price"><span>Stop % (on average)</span><input v-model.number="params.m_stop_pct" type="number" min="0.5" max="99" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="A trade that goes into a second day is sold as soon as the price is back to its average price plus this % (0 = cost to cost)"><span>Day 2: out at +%</span><input v-model.number="params.m_target_pct" type="number" min="0" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="A same-day trade that reaches this % profit gets a trailing stop"><span>Trail after +%</span><input v-model.number="params.m_trail_start_pct" type="number" min="0.5" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="The trailing stop sits this % under the highest price"><span>Trail gap %</span><input v-model.number="params.m_trail_pct" type="number" min="0.5" max="50" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="A trade still open after this many trading days is sold at that day's close. Candles are only downloaded about 10 days past a signal."><span>Max hold (days)</span><input v-model.number="params.m_max_hold_days" type="number" min="1" max="30" class="form-control form-control-sm" /></label>
                <label class="sc-check sc-field" title="Off: a trade in profit at the close is sold there. On: one that closes up by the trail-after % keeps running with the trailing stop"><input v-model="params.m_carry_winners" type="checkbox" /> Let +5 % winners run overnight</label>
            </fieldset>

            <fieldset v-else class="sc-group">
                <legend>Exit</legend>
                <label class="sc-field" title="0 = sell the same day"><span>Hold (days)</span><input v-model.number="params.hold_days" type="number" min="0" max="30" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Sell at</span>
                    <select v-model="params.exit_at" class="form-select form-select-sm"><option value="close">day close</option><option value="open" :disabled="!holdsOvernight">day open (hold ≥ 1)</option></select>
                </label>
                <label class="sc-field"><span>Target %</span><input v-model="params.target_pct" type="number" min="0" step="0.5" placeholder="none" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Stop %</span><input v-model="params.stop_pct" type="number" min="0" step="0.5" placeholder="none" class="form-control form-control-sm" /></label>
            </fieldset>

            <fieldset class="sc-group">
                <legend>Which signals</legend>
                <div class="sc-field sc-caps"><span>Market cap</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <label v-for="c in CAPS" :key="c" class="sc-check"><input type="checkbox" :checked="params.caps.includes(c)" @change="toggleCap(c)" /> {{ c.replace('cap', '') }}</label>
                    </div>
                </div>
                <label class="sc-check sc-field"><input v-model="params.exclude_etf" type="checkbox" /> Leave out ETFs / funds</label>
                <label class="sc-field" title="Average close of the last sessions"><span>Min price ₹</span><input v-model.number="params.min_price" type="number" min="0" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Average rupees traded per day over the previous sessions (close × volume)"><span>Min traded / day (₹ cr)</span><input v-model.number="params.min_turnover_cr" type="number" min="0" step="0.5" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="How many previous sessions the two checks above look at"><span>…over sessions</span><input v-model.number="params.liquidity_days" type="number" min="1" max="250" class="form-control form-control-sm" /></label>
                <label class="sc-field" title="Move from the previous close at the moment of the signal"><span>Day move ≥ %</span><input v-model="params.min_day_change" type="number" step="0.5" placeholder="any" class="form-control form-control-sm" /></label>
                <label class="sc-field"><span>Day move ≤ %</span><input v-model="params.max_day_change" type="number" step="0.5" placeholder="any" class="form-control form-control-sm" /></label>
                <label class="sc-check sc-field" title="Only when the signal candle closes above the day's VWAP"><input v-model="params.above_vwap" type="checkbox" /> Above VWAP</label>
            </fieldset>
        </div>
    </div>
</template>
