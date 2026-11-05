<script setup>
  const { title } = defineProps(['title'])
</script>

<template>
  <div class="d-flex flex-stack ">
    <div class="page-title d-flex flex-column justify-content-center flex-wrap me-3 ">
      <h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">{{ title }}</h1>
    </div>
    <div class="d-flex align-items-center gap-2 gap-lg-3">
      <slot></slot>
    </div>
  </div>
</template>