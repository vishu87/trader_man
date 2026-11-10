<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const props = defineProps({
    show: { type: Boolean, required: true },
    list: { type: Object,  default: null },   // null = add
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});

watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    model.value = props.list ? { id: props.list.id, name: props.list.name } : { name: '' };
});

const submit = handleSubmit(() => {
    loading.value = true;
    DBService.postData('/api/watchlist/list/save', model.value).then((data) => {
        loading.value = false;
        if (data.success) {
            emit('saved', data.id);
            emit('close');
        } else {
            alert(data.message);
        }
    });
});
</script>

<template>
    <Aside :show="show" :title="list ? 'Edit Watchlist' : 'New Watchlist'" subtitle="A named list of symbols to keep an eye on" size="sm" @close="emit('close')">
        <form @submit.prevent="submit()" id="watchlistForm" novalidate>
            <div class="row g-3">
                <FormInput label="Name" name="name" :req="true" v-model="model.name" cls="col-12" :disabled="loading" />
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="watchlistForm" class="aside-btn-primary" :disabled="loading">{{ loading ? 'Saving…' : 'Save' }}</button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
