<script setup>
const s3_url_link = import.meta.env.VITE_S3_URL
const url_link = import.meta.env.VITE_APP_URL
const { title="View", link, type="s3" } = defineProps(['title', 'link', 'type'])
</script>

<template>
  <a class="btn btn-info btn-sm" :href=" type == 's3' ? s3_url_link + link : url_link + link" target="_blank">{{title}}</a>
</template>