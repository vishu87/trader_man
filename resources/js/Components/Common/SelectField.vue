<script setup>
import { ref, onMounted } from 'vue';

const { label, error, options=[], opt_id='value', opt_name='label', req=false, editable=true, select_name='Select', cls='', disabled=false} = defineProps(['label','error','options','opt_id','opt_name','req','editable','select_name','cls','disabled'])
const model = defineModel() 

const selected_name = ref('')

onMounted(() => {
    onChange();
})

function onChange() {
    // console.log(options)
    selected_name.value = ''
    for (let i = 0; i < options.length; i++) {
        if(options[i][opt_id] == model.value) {
            selected_name.value = options[i][opt_name]
        }
    }
}

</script>

<template>
    <div :class="`form-group ${cls}`" v-if="editable">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <select class="form-control" v-model="model" @change="onChange()" :disabled="disabled">
            <option value="">{{ select_name }}</option>
            <option v-for="opt in options" :value="opt[opt_id]">{{ opt[opt_name] }}</option>
        </select>
    </div>
    <div v-if="!editable">
        {{selected_name}}
    </div>
</template>