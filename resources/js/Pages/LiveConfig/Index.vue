<script setup>
import { ref, computed, onMounted } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum } from '@/Service/Utils/Format.js';

// The rules live trading runs on. Saved here, read by the trading engine (App\Services\Live). Separate from the backtest screen:
// changing the backtest can never change what is traded, and nothing is traded from here — this only saves settings.
const { handleSubmit } = useForm();

const loaded    = ref(false);
const saving    = ref(false);
const banner    = ref(null);
const errors    = ref({});
const m         = ref({});
const saved     = ref(null);          // what is stored now (for the confirm-live check)
const webhookUrl  = ref(null);
const webhookUrlKey = ref(null);
const copiedKey = ref(false);
const brokerReady = ref(false);
const copied    = ref(false);
const seenAlerts = ref([]);           // alert names ChartInk has sent lately: [{ name, calls, last_at, enabled }]
const newAlert  = ref('');

// ─── the alerts that trade: any number of them ───
function addAlert(name) {
    const a = (typeof name === 'string' ? name : newAlert.value).trim().replace(/,+$/, '').trim();
    if (!a) return;
    if (!/^[A-Za-z0-9 _\-.]{1,100}$/.test(a)) { errors.value = { ...errors.value, alert_names: 'Alert names can use letters, numbers, spaces and - _ .' }; return; }
    if (!m.value.alert_names.includes(a)) m.value.alert_names.push(a);
    newAlert.value = '';
    const { alert_names, ...rest } = errors.value; errors.value = rest;
}
// Enter or a comma adds what is typed
function onAlertKey(e) {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addAlert(); }
}
function removeAlert(a) { m.value.alert_names = m.value.alert_names.filter((x) => x !== a); }
const suggestions = computed(() => seenAlerts.value.filter((s) => !(m.value.alert_names || []).includes(s.name)));

const MODES   = [{ value: 'dry_run', label: 'Dry run — real prices, simulated orders' }, { value: 'live', label: 'LIVE — real orders on Zerodha' }];
const ONOFF   = [{ value: 'off', label: 'Off — no new buys or averages' }, { value: 'on', label: 'On' }];
const ORDERS  = [{ value: 'limit', label: 'Limit (a little above / below the price)' }, { value: 'market', label: 'Market' }];
const WATCH   = [{ value: 'day', label: 'The whole day — until the buying window ends' }, { value: 'minutes', label: 'For a set time after its candle closes' }];
const YESNO   = [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }];

function toForm(c) {
    return {
        enabled: c.enabled ? 'on' : 'off', mode: c.mode, alert_names: [...c.alert_names], max_alert_age_minutes: c.max_alert_age_minutes, watch_mode: c.watch_all_day ? 'day' : 'minutes', signal_valid_minutes: c.signal_valid_minutes,
        q_max_day_chg: c.quality.max_day_chg, q_max_from_low: c.quality.max_from_low, q_max_vwap_dist: c.quality.max_vwap_dist, q_min_rsi: c.quality.min_rsi, q_max_rsi: c.quality.max_rsi,
        q_min_vol_ratio: c.quality.min_vol_ratio, q_daily_uptrend: c.quality.daily_uptrend ? 'yes' : 'no', q_d_green_min: c.quality.d_green_min, q_d_green_days: c.quality.d_green_days,
        q_max_chg_5d: c.quality.max_chg_5d, q_max_daily_rsi: c.quality.max_daily_rsi, q_max_chg_1m: c.quality.max_chg_1m,
        first_amount: c.first_amount, add_amount: c.add_amount, max_adds: c.max_adds, add_drop_pct: c.add_drop_pct, entry_from: c.entry_from, entry_until: c.entry_until,
        hold_days: c.hold_days ?? '', exit_time: c.exit_time, target_pct: c.target_pct ?? '', stop_pct: c.stop_pct ?? '',
        min_price: c.min_price, min_turnover_cr: c.min_turnover / 10000000, liquidity_days: c.liquidity_days, exclude_etf: c.exclude_etf ? 'yes' : 'no',
        order_type: c.order_type, limit_buffer_pct: c.limit_buffer_pct, exit_buffer_pct: c.exit_buffer_pct, entry_timeout_minutes: c.entry_timeout_minutes, exit_timeout_minutes: c.exit_timeout_minutes,
        max_open_positions: c.max_open_positions, max_capital_total: c.max_capital_total, max_new_capital_per_day: c.max_new_capital_per_day,
    };
}

function toConfig(f) {
    return {
        enabled: f.enabled === 'on', mode: f.mode, alert_names: f.alert_names, max_alert_age_minutes: f.max_alert_age_minutes, watch_all_day: f.watch_mode === 'day', signal_valid_minutes: f.signal_valid_minutes,
        quality: {
            max_day_chg: f.q_max_day_chg, max_from_low: f.q_max_from_low, max_vwap_dist: f.q_max_vwap_dist, min_rsi: f.q_min_rsi, max_rsi: f.q_max_rsi, min_vol_ratio: f.q_min_vol_ratio,
            daily_uptrend: f.q_daily_uptrend === 'yes', d_green_min: f.q_d_green_min, d_green_days: f.q_d_green_days, max_chg_5d: f.q_max_chg_5d, max_daily_rsi: f.q_max_daily_rsi, max_chg_1m: f.q_max_chg_1m,
        },
        first_amount: f.first_amount, add_amount: f.add_amount, max_adds: f.max_adds, add_drop_pct: f.add_drop_pct, entry_from: f.entry_from, entry_until: f.entry_until,
        hold_days: f.hold_days, exit_time: f.exit_time, target_pct: f.target_pct, stop_pct: f.stop_pct,
        min_price: f.min_price, min_turnover: (Number(f.min_turnover_cr) || 0) * 10000000, liquidity_days: f.liquidity_days, exclude_etf: f.exclude_etf === 'yes',
        order_type: f.order_type, limit_buffer_pct: f.limit_buffer_pct, exit_buffer_pct: f.exit_buffer_pct, entry_timeout_minutes: f.entry_timeout_minutes, exit_timeout_minutes: f.exit_timeout_minutes,
        max_open_positions: f.max_open_positions, max_capital_total: f.max_capital_total, max_new_capital_per_day: f.max_new_capital_per_day,
    };
}

function load() {
    return DBService.postData('/api/live/config', {}).then((data) => {
        if (!data.success) { banner.value = { bad: true, message: typeof data.message === 'string' ? data.message : 'Could not load the settings' }; return; }
        saved.value = data.config;
        m.value = toForm(data.config);
        seenAlerts.value = data.seen_alerts || [];
        webhookUrl.value = data.webhook_url;
        webhookUrlKey.value = data.webhook_url_key;
        brokerReady.value = data.broker_ready;
        loaded.value = true;
    });
}

onMounted(load);

// the most one stock can cost, from what is typed
const maxPerStock = computed(() => {
    if (!loaded.value) return 0;
    const first = Number(m.value.first_amount) || 0, add = Number(m.value.add_amount) || 0, n = Number(m.value.max_adds) || 0;
    return first + add * n;
});

const submit = handleSubmit(() => {
    banner.value = null;
    errors.value = {};

    // real orders are a deliberate step
    const goingLive = m.value.mode === 'live' && (saved.value.mode !== 'live' || !saved.value.enabled);
    if (goingLive && !confirm('LIVE MODE\n\nWith this saved and trading switched On, REAL buy and sell orders will be sent to your Zerodha account as alerts arrive — up to ₹' + fmtNum(Number(m.value.max_capital_total) || 0) + ' held in total.\n\nThe dry-run mode is the safe way to watch what it would do first.\n\nSave in LIVE mode?')) return;

    saving.value = true;
    DBService.postData('/api/live/config/save', { config: toConfig(m.value), confirm_live: goingLive }).then((data) => {
        saving.value = false;
        if (!data.success) {
            errors.value = data.errors || {};
            banner.value = { bad: true, message: typeof data.message === 'string' ? data.message : 'Could not save' };
            return;
        }
        banner.value = { ok: true, message: 'Saved. The trading engine uses these values from the next alert and the next minute’s check.' };
        saved.value = data.config;
        m.value = toForm(data.config);
    });
});

function copyUrl(which) {
    const url = which === 'key' ? webhookUrlKey.value : webhookUrl.value;
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
        const r = which === 'key' ? copiedKey : copied;
        r.value = true; setTimeout(() => r.value = false, 1500);
    });
}
</script>

<template>
    <PageHeader title="Live Config" subtitle="The rules live trading runs on — saved here, separate from the backtest" :show-search="false" />

    <div class="page-canvas">

        <div v-if="banner" class="pf-alert zt-result" :class="{ 'pf-alert-ok': banner.ok, 'pf-alert-bad': banner.bad }">
            <i class="bi" :class="banner.bad ? 'bi-x-circle-fill' : 'bi-check-circle-fill'"></i>
            <div class="flex-grow-1">{{ banner.message }}</div>
            <button class="tbl-icon-btn" @click="banner = null"><i class="bi bi-x-lg"></i></button>
        </div>

        <div v-if="!loaded" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Loading…</div>

        <form v-else id="liveConfigForm" novalidate @submit.prevent="submit()">

            <div v-if="m.mode === 'live' && m.enabled === 'on'" class="lc-warn"><i class="bi bi-exclamation-triangle-fill"></i> <div><strong>Live mode is on.</strong> Real orders are sent to Zerodha when alerts arrive. Switch trading Off to stop new buys (open positions keep being watched for their exits).</div></div>
            <div v-else class="lc-note"><i class="bi bi-shield-check"></i> <div><strong>{{ m.mode === 'live' ? 'Live mode, trading is Off' : 'Dry run' }}.</strong> {{ m.mode === 'live' ? 'No new orders will be sent until trading is switched On.' : 'Real prices from Zerodha, but every order is only simulated: nothing is ever sent.' }} New installs start here, with trading Off.</div></div>

            <div class="lc-grid">

                <section class="card-panel lc-card">
                    <h6>Trading</h6>
                    <div class="row g-3">
                        <FormSelect label="Trading" name="enabled" cls="col-6" v-model="m.enabled" :options="ONOFF" select_name="Choose" :req="true" />
                        <FormSelect label="Mode" name="mode" cls="col-6" v-model="m.mode" :options="MODES" select_name="Choose" :req="true" />
                        <div class="col-12">
                            <label class="form-label" style="font-size:12.5px;font-weight:600">Alerts that trade <span class="text-danger">*</span></label>
                            <div class="alert-chips">
                                <span v-for="a in m.alert_names" :key="a" class="alert-chip">{{ a }}<button type="button" title="Stop trading this alert" @click="removeAlert(a)"><i class="bi bi-x"></i></button></span>
                                <input v-model="newAlert" type="text" class="alert-chip-input" placeholder="Add an alert name…" @keydown="onAlertKey" @blur="addAlert()" />
                            </div>
                            <div v-if="suggestions.length" class="lc-hint" style="margin-top:6px">
                                Sent by ChartInk lately, not trading — click to add:
                                <button v-for="s in suggestions" :key="s.name" type="button" class="alert-suggest" :title="s.calls + ' call(s), last ' + s.last_at" @click="addAlert(s.name)">+ {{ s.name }}</button>
                            </div>
                        </div>
                        <FormInput label="Ignore alerts older than (min)" name="max_alert_age_minutes" type="number" cls="col-6" v-model="m.max_alert_age_minutes" :req="true" />
                        <FormSelect label="A waiting signal is watched" name="watch_mode" cls="col-6" v-model="m.watch_mode" :options="WATCH" select_name="Choose" :req="true" />
                        <FormInput v-if="m.watch_mode === 'minutes'" label="…for this long (min)" name="signal_valid_minutes" type="number" cls="col-6" v-model="m.signal_valid_minutes" :req="true" />
                    </div>
                    <p class="lc-hint">
                        <strong>Every alert listed here trades</strong> — add as many as you run on ChartInk. If two alerts (or the same one) send the same stock on the same day, only the <em>first</em> is judged while it is being watched, was bought or was rated Skip; the later one is kept apart on the Live Signals page under <em>Duplicates</em>.
                        A signal that is <em>Watch</em> (or <em>Can enter</em> but has no free slot) is checked again on every closed 5-minute candle, <strong>{{ m.watch_mode === 'day' ? 'all day, until ' + (m.entry_until || 'the buying window ends') : 'for ' + (m.signal_valid_minutes || '…') + ' min after its candle closed' }}</strong>. Keep "the whole day" while ChartInk is set to send a stock only once.
                    </p>
                    <div v-for="k in ['enabled', 'mode', 'alert_names', 'max_alert_age_minutes', 'watch_all_day', 'signal_valid_minutes']" :key="k">
                        <div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div>
                    </div>
                </section>

                <section class="card-panel lc-card">
                    <h6>Buying</h6>
                    <div class="row g-3">
                        <FormInput label="First buy (₹)" name="first_amount" type="number" cls="col-6" v-model="m.first_amount" :req="true" />
                        <FormInput label="Average with (₹)" name="add_amount" type="number" cls="col-6" v-model="m.add_amount" :req="true" />
                        <FormInput label="Max extra buys" name="max_adds" type="number" cls="col-6" v-model="m.max_adds" :req="true" />
                        <FormInput label="Then every … % lower" name="add_drop_pct" type="number" cls="col-6" v-model="m.add_drop_pct" :req="true" />
                        <FormInput label="Buy from" name="entry_from" type="time" cls="col-6" v-model="m.entry_from" :req="true" />
                        <FormInput label="Buy until" name="entry_until" type="time" cls="col-6" v-model="m.entry_until" :req="true" />
                    </div>
                    <p class="lc-hint">
                        <strong>1st buy</strong> once the alert's 5-minute candle has <em>closed green</em> (close above open; we trade on closed candles only). <strong>2nd buy</strong> when the price falls to the 5-minute 20 SMA.
                        <strong>3rd and later buys</strong> each time the price is {{ m.add_drop_pct || '…' }}% under the <em>last</em> buy. Extra buys only happen while the price is <strong>under the day's VWAP</strong>, and follow the price, not repeated alerts; "Max extra buys" of 3 means 4 buys in all.
                        At most <strong>₹{{ fmtNum(maxPerStock) }}</strong> goes into one stock.
                    </p>
                    <div v-for="k in ['first_amount', 'add_amount', 'max_adds', 'add_drop_pct', 'entry_from', 'entry_until']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>

                <section class="card-panel lc-card">
                    <h6>Selling</h6>
                    <div class="row g-3">
                        <FormInput label="Hold for (days)" name="hold_days" type="number" cls="col-6" v-model="m.hold_days" placeholder="no time exit" />
                        <FormInput label="Sell from (on the last day)" name="exit_time" type="time" cls="col-6" v-model="m.exit_time" :req="true" />
                        <FormInput label="Target %" name="target_pct" type="number" cls="col-6" v-model="m.target_pct" placeholder="none" />
                        <FormInput label="Stop loss %" name="stop_pct" type="number" cls="col-6" v-model="m.stop_pct" placeholder="none" />
                    </div>
                    <p class="lc-hint">For now a position is sold at the <strong>profit target</strong>, or by hand with the <strong>Exit</strong> button on the Positions tab. Leave <em>Hold for</em> and <em>Stop loss</em> empty for that. Hold 0 = sell the same day; 1 = the next trading day (weekends skipped; there is no holiday calendar). Target and stop are measured from your <em>average</em> price and checked every minute.</p>
                    <div v-for="k in ['hold_days', 'exit_time', 'target_pct', 'stop_pct']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>

                <section class="card-panel lc-card">
                    <h6>Signal check — Can enter / Watch / Skip</h6>
                    <div class="row g-3">
                        <FormInput label="Day move at most (%)" name="q_max_day_chg" type="number" cls="col-4" v-model="m.q_max_day_chg" :req="true" />
                        <FormInput label="Off the day's low at most (%)" name="q_max_from_low" type="number" cls="col-4" v-model="m.q_max_from_low" :req="true" />
                        <FormInput label="Above VWAP at most (%)" name="q_max_vwap_dist" type="number" cls="col-4" v-model="m.q_max_vwap_dist" :req="true" />
                        <FormInput label="5-min RSI at least" name="q_min_rsi" type="number" cls="col-4" v-model="m.q_min_rsi" :req="true" />
                        <FormInput label="5-min RSI at most" name="q_max_rsi" type="number" cls="col-4" v-model="m.q_max_rsi" :req="true" />
                        <FormInput label="Volume at least (× average)" name="q_min_vol_ratio" type="number" cls="col-4" v-model="m.q_min_vol_ratio" :req="true" />
                        <FormSelect label="Daily close above 20 SMA" name="q_daily_uptrend" cls="col-4" v-model="m.q_daily_uptrend" :options="YESNO" select_name="Choose" :req="true" />
                        <FormInput label="Green daily candles at least" name="q_d_green_min" type="number" cls="col-4" v-model="m.q_d_green_min" :req="true" />
                        <FormInput label="…of the last (days)" name="q_d_green_days" type="number" cls="col-4" v-model="m.q_d_green_days" :req="true" />
                        <FormInput label="5-day rise at most (%)" name="q_max_chg_5d" type="number" cls="col-4" v-model="m.q_max_chg_5d" :req="true" />
                        <FormInput label="Daily RSI at most" name="q_max_daily_rsi" type="number" cls="col-4" v-model="m.q_max_daily_rsi" :req="true" />
                        <FormInput label="Last month rise at most (%)" name="q_max_chg_1m" type="number" cls="col-4" v-model="m.q_max_chg_1m" :req="true" />
                    </div>
                    <p class="lc-hint">Judged on the <em>closed</em> 5-minute candle, from Zerodha. A failed <strong>timing</strong> check (candle not green, run too far today, RSI, VWAP, volume) = <strong>Watch</strong>: checked again on every new candle. A failed <strong>daily / monthly</strong> check = <strong>Skip</strong>. All pass = <strong>Can enter</strong>, validated once more just before the buy. The daily trend needs the close above the 20 SMA <em>and</em> mostly green daily candles, so one old spike followed by red candles does not count as an uptrend.</p>
                    <div v-for="k in ['q_max_day_chg', 'q_max_from_low', 'q_max_vwap_dist', 'q_min_rsi', 'q_max_rsi', 'q_min_vol_ratio', 'q_daily_uptrend', 'q_d_green_min', 'q_d_green_days', 'q_max_chg_5d', 'q_max_daily_rsi', 'q_max_chg_1m']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>

                <section class="card-panel lc-card">
                    <h6>Which stocks</h6>
                    <div class="row g-3">
                        <FormInput label="Minimum price (₹)" name="min_price" type="number" cls="col-6" v-model="m.min_price" :req="true" />
                        <FormInput label="Minimum traded a day (₹ crore)" name="min_turnover_cr" type="number" cls="col-6" v-model="m.min_turnover_cr" :req="true" />
                        <FormInput label="…averaged over sessions" name="liquidity_days" type="number" cls="col-6" v-model="m.liquidity_days" :req="true" />
                        <FormSelect label="Leave out ETFs / funds" name="exclude_etf" cls="col-6" v-model="m.exclude_etf" :options="YESNO" select_name="Choose" :req="true" />
                    </div>
                    <div v-for="k in ['min_price', 'min_turnover', 'liquidity_days', 'exclude_etf']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>

                <section class="card-panel lc-card">
                    <h6>How orders are sent</h6>
                    <div class="row g-3">
                        <FormSelect label="Order type" name="order_type" cls="col-12" v-model="m.order_type" :options="ORDERS" select_name="Choose" :req="true" />
                        <FormInput label="Buy limit above price (%)" name="limit_buffer_pct" type="number" cls="col-6" v-model="m.limit_buffer_pct" :req="true" />
                        <FormInput label="Sell limit below price (%)" name="exit_buffer_pct" type="number" cls="col-6" v-model="m.exit_buffer_pct" :req="true" />
                        <FormInput label="Cancel an unfilled buy after (min)" name="entry_timeout_minutes" type="number" cls="col-6" v-model="m.entry_timeout_minutes" :req="true" />
                        <FormInput label="Re-try an unfilled sell after (min)" name="exit_timeout_minutes" type="number" cls="col-6" v-model="m.exit_timeout_minutes" :req="true" />
                    </div>
                    <div v-for="k in ['order_type', 'limit_buffer_pct', 'exit_buffer_pct', 'entry_timeout_minutes', 'exit_timeout_minutes']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>

                <section class="card-panel lc-card">
                    <h6>Limits</h6>
                    <div class="row g-3">
                        <FormInput label="Most open stocks at once" name="max_open_positions" type="number" cls="col-12" v-model="m.max_open_positions" :req="true" />
                        <FormInput label="Most money held in total (₹)" name="max_capital_total" type="number" cls="col-6" v-model="m.max_capital_total" :req="true" />
                        <FormInput label="Most new buying a day (₹)" name="max_new_capital_per_day" type="number" cls="col-6" v-model="m.max_new_capital_per_day" :req="true" />
                    </div>
                    <p class="lc-hint">A <em>Can enter</em> signal that would go over any of these waits (it is validated again, then bought, when there is room), and the Live Signals page says which limit.</p>
                    <div v-for="k in ['max_open_positions', 'max_capital_total', 'max_new_capital_per_day']" :key="k"><div v-if="errors[k]" class="lc-err">{{ errors[k] }}</div></div>
                </section>
            </div>

            <section class="card-panel lc-card mt-3">
                <h6>ChartInk webhook</h6>
                <p class="lc-hint" style="margin-top:0">In your ChartInk alert, tick “Send webhook” and use this URL. Stocks are looked up on NSE first, then BSE.</p>
                <div class="d-flex gap-2">
                    <input class="form-control form-control-sm mono" :value="webhookUrl" readonly @focus="$event.target.select()" />
                    <button type="button" class="btn-secondary-sm" @click="copyUrl('plain')"><i class="bi" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i> {{ copied ? 'Copied' : 'Copy' }}</button>
                </div>
                <p class="lc-hint">This URL is open: it works in <strong>Dry run</strong>, and is refused in <strong>Live</strong> mode, because anyone who knew it could otherwise trigger real orders. For live trading give ChartInk the secret form:</p>
                <template v-if="webhookUrlKey">
                    <div class="d-flex gap-2">
                        <input class="form-control form-control-sm mono" :value="webhookUrlKey" readonly @focus="$event.target.select()" />
                        <button type="button" class="btn-secondary-sm" @click="copyUrl('key')"><i class="bi" :class="copiedKey ? 'bi-check2' : 'bi-clipboard'"></i> {{ copiedKey ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <p class="lc-hint" style="margin-top:6px">Keep that one private.</p>
                </template>
                <p v-else class="lc-err" style="margin:0">No webhook secret is set. Add <span class="mono">CHARTINK_WEBHOOK_SECRET=…</span> to .env; until then live mode refuses every call.</p>
                <p class="lc-hint">Zerodha token for today: <span class="status-chip" :class="brokerReady ? 'chip-green' : 'chip-amber'">{{ brokerReady ? 'found' : 'not found yet' }}</span>
                    — prices and orders need it. It is fetched from the token link every hour (and by Dashboard → Refresh Token). The once-a-minute check is <span class="mono">php artisan live:tick</span>, run by the scheduler cron.</p>
            </section>

            <div class="lc-actions">
                <button type="submit" class="btn-new-filing" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span> Save live config</button>
            </div>
        </form>
    </div>
</template>
