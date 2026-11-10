<script setup>
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const props = defineProps({
    show:   { type: Boolean, required: true },
    bucket: { type: Object,  default: null },   // null = add
});

const emit = defineEmits(['close', 'saved']);

const { handleSubmit, resetForm } = useForm();

const loading = ref(false);
const model   = ref({});

const allOptions = [
    { value: 0, label: 'Included in All' },
    { value: 1, label: 'Excluded from All' },
];

// Aside content is mounted only while open, so set the model before it renders
watch(() => props.show, (val) => {
    if (!val) return;
    resetForm();
    model.value = props.bucket
        ? { id: props.bucket.id, name: props.bucket.name, description: props.bucket.description || '', exclude_from_all: props.bucket.exclude_from_all ? 1 : 0 }
        : { name: '', description: '', exclude_from_all: 0 };
});

const submit = handleSubmit(() => {
    loading.value = true;
    DBService.postData('/api/portfolio/bucket/save', model.value).then((data) => {
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
    <Aside
        :show="show"
        :title="bucket ? 'Edit Bucket' : 'New Bucket'"
        subtitle="A bucket is a named list of stocks"
        size="sm"
        @close="emit('close')"
    >
        <form @submit.prevent="submit()" id="bucketForm" novalidate>
            <div class="row g-3">
                <FormInput label="Name" name="name" :req="true" v-model="model.name" cls="col-12" :disabled="loading" />
                <FormInput label="Description" name="description" v-model="model.description" cls="col-12" :disabled="loading" />
                <FormSelect
                    label="All tab" name="exclude_from_all" :req="true" cls="col-12"
                    v-model="model.exclude_from_all" :options="allOptions" :disabled="loading"
                />
                <p class="col-12 text-muted mb-0" style="font-size:12px">
                    An excluded bucket keeps its own tab, but its stocks are left out of the All tab — list, totals, counts and export.
                </p>
            </div>
        </form>

        <template #footer>
            <div class="d-flex gap-2">
                <button type="submit" form="bucketForm" class="aside-btn-primary" :disabled="loading">
                    {{ loading ? 'Saving…' : 'Save' }}
                </button>
                <button type="button" class="aside-btn-cancel" @click="emit('close')" :disabled="loading">Cancel</button>
            </div>
        </template>
    </Aside>
</template>
