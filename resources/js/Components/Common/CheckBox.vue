<script setup>
const { label, error, name="", value="", cls='' } = defineProps(['label', 'error', 'name', 'value', 'cls'])
const model = defineModel() 
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label" style="display: block;">{{label}}</label>
        <input type="checkbox" v-model="model" :value="value">{{name}}
    </div>
</template>