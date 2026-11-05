<script setup>
defineProps({ title: String, show_header: { type: Boolean, default: false } });
</script>

<template>
  <div class="page-canvas">
    <slot></slot>
  </div>
</template>
