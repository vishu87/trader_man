<script setup>
import { ref, computed, onMounted } from "vue";
const { html_content } = defineProps(['html_content']);

const processedHtml = computed(() => {
  const urlRegex = /((https?:\/\/[^\s<>"']+))/g;
  return html_content.replace(
    urlRegex,
    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
  );
});

</script>

<template>
  <div v-html="processedHtml"></div>
</template>

<style type="text/css" scoped>

</style>