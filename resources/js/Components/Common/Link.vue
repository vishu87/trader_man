<script setup>
const url_link = import.meta.env.VITE_APP_URL
const s3_url_link = import.meta.env.VITE_S3_URL
const { title='View', href, cls='', type='', target='' } = defineProps(['title', 'href', 'cls', 'type','target'])
</script>

<template>
  <a :class="cls" :href=" type == 's3' ? s3_url_link + href : url_link + href" :target="target">{{title}}</a>
</template>