<script setup>
const { title, subtitle = '' } = defineProps(['title', 'subtitle'])
</script>

<template>
  <div class="page-header">
    <div class="page-header-inner">
      <div><div class="page-title">{{ title }}</div><div class="page-subtitle" v-if="subtitle">{{ subtitle }}</div></div>
      <div class="page-header-actions">
        <slot></slot>
      </div>
    </div>
  </div>
</template>
