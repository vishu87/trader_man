<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';

const {
    name,
    modelValue,
    cls = '',
    req = false,
    label = '',
    validate = '',
    placeholder = '',
    disabled = false,
    rows = 3
} = defineProps([
    'name',
    'modelValue',
    'cls',
    'req',
    'label',
    'validate',
    'placeholder',
    'disabled',
    'rows'
]);

const processedReq = computed(() => {
    if (!req && !validate) {
        return null;
    }

    let obj = yup.string();

    if (req) {
        obj = obj.required();
    }

    if (validate === 'email') {
        obj = obj.email();
    } else if (validate === 'url') {
        obj = obj.url();
    }

    return obj;
});


const { value, errorMessage } = useField(() => name, processedReq, {
  syncVModel: true,
});

</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label" >{{label}} <span class="error" v-if="req">*</span></label>
        <textarea
            v-model="value"
            class="form-control  form-control-solid"
            :placeholder="placeholder"
            :rows="rows"
            :disabled="disabled"
        >
                
        </textarea>
        <span class="text-danger" style="font-size: 11px">{{ errorMessage }}</span>                 
    </div>
</template>