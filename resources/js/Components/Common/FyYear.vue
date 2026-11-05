<script setup>
const props = defineProps({ year: [Number, String] });
const label = () => props.year ? `${props.year}-${String(Number(props.year) + 1).slice(-2)}` : '—';
</script>
<template>{{ label() }}</template>
