<script setup>
const { disabled=false, processing=false, cls='btn-light' } = defineProps(['disabled','processing','cls'])
</script>

<template>
  <button :class="`btn ${cls}`" type="submit" :disabled="disabled || processing">
    <slot></slot>
    <span v-if="processing" class="spinner-border text-white spinner-border-sm" role="status" style="margin-left: 5px"></span>
  </button>
</template>