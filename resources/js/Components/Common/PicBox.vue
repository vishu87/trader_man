<script setup>
const { pic, color } = defineProps(['pic','color'])
</script>

<template>
  <div class="table-row-pic">
    <img :src="pic" :style="{ border: '3px solid' + color }" />
  </div>
</template>