<script setup>
const { disabled=false, processing=false, cls='btn-light', spinner_cls='spinner-border text-white spinner-border-sm' } = defineProps(['disabled','processing','cls','spinner_cls'])
</script>

<template>
  <button :class="`btn ${cls}`" type="button" :disabled="disabled || processing" @click="$emit('clickFn')">
    <slot v-if="!processing"></slot>
    <span v-if="processing" :class="spinner_cls" role="status" style="margin-left: 5px"></span>
  </button>
</template>