<script setup>
const { label, error, req = false, type="text", placeholder="", cls='', disabled=false} = defineProps(['label','error','req','type','placeholder','cls','disabled'])
const model = defineModel() 
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <textarea class="form-control" v-model="model" :placeholder="placeholder" :disabled="disabled"></textarea>
    </div>
</template>