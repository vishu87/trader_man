<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';
import { fmtNum } from '@/Service/Utils/Format.js';

const props = defineProps({
    show:    { type: Boolean, required: true },
    maxLoss: { type: Number,  default: 5000 },
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    model.value = { max_loss_per_trade: props.maxLoss };
});

// a worked example that follows what is typed, so the rule is obvious
const example = computed(() => {
    const loss = Number(model.value.max_loss_per_trade);
    if (!(loss > 0)) return null;
    const buy = 500, sl = 470, risk = buy - sl;
    const qty = Math.floor(loss / risk + 1e-9);
    return { buy, sl, risk, qty, capital: qty * buy, atSl: qty * risk };
});

const submit = handleSubmit(() => {
    loading.value = true;
    DBService.postData('/api/settings/risk/save', model.value).then((data) => {
        loading.value = false;
        if (data.success) {
            emit('saved', data.max_loss_per_trade);
            emit('close');
        } else {
            alert(data.message);
        }
    });
});
</script>

<template>
    <Aside :show="show" title="Risk Settings" subtitle="Position sizing for the watchlist" size="sm" @close="emit('close')">
        <form @submit.prevent="submit()" id="riskForm" novalidate>
            <div class="row g-3">
                <FormInput
                    label="Max loss per trade" name="max_loss_per_trade" type="number" :req="true"
                    left_box_text="₹" v-model="model.max_loss_per_trade" cls="col-12" :disabled="loading"
                />

                <div class="col-12 p-3 rounded" style="background:#f8fafc;border:1px solid #E7EBF1;font-size:13px;color:#475467">
                    <div class="mb-1"><strong>Quantity = max loss ÷ (buy price − stop loss)</strong>, rounded down.</div>
                    <div class="text-muted mb-2">So the loss if the stop loss hits never exceeds this figure.</div>
                    <div v-if="example">
                        Example: buy {{ example.buy }}, stop loss {{ example.sl }} → risk {{ example.risk }} per share<br>
                        → quantity <strong>{{ fmtNum(example.qty) }}</strong>, capital ₹{{ fmtNum(example.capital) }},
                        loss at stop loss ₹{{ fmtNum(example.atSl) }}
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="riskForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Saving…' : 'Save' }}</button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
