<script setup>

</script>

<template>
  <div class="card home-card" style="margin-bottom: 20px">
    <div class="card-body">
      <slot></slot>
    </div>
  </div>
</template>

<style>
  .home-card {
    td {
      &.yellow{
        background: yellow; 
      }
      &.blue{
        background: #a9a9ff; 
      }
    }
  }
</style>
