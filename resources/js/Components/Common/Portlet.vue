<script setup>
const { show_header = false} = defineProps(['show_header'])
</script>

<template>
  <div class="portlet" style="position: relative;">
    <div class="portlet-head" v-if="show_header">
      <slot name="header"></slot>
    </div>
    <div class="portlet-body">
      <slot></slot>
    </div>
  </div>
</template>