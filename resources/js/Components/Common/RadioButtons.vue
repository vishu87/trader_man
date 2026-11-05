<script setup>
const { label, error, options, opt_id='value', opt_name='label' , cls='', cls2=''} = defineProps(['label','error','options','opt_id','opt_name','cls','cls2'])
const model = defineModel()

</script>

<template>
    <div :class="`form-group ${cls}`">
        <label :class="cls2">{{label}}</label>
        <div v-for="opt in options">
            <input type="radio" v-model="model" :value="opt[opt_id]" /> <label>{{ opt[opt_name] }}</label>
        </div>
    </div>
</template>