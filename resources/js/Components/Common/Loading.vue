<script setup>
const { loading, type="loader", max = 10 } = defineProps(['loading', 'type', 'max'])

const x = [1,2,3,4,5,6,7,8,9,10]
</script>

<template>
  <table class="table" v-if="loading && type == 'table' "> 
    <tr v-for="(item, idx) in x" v-show="idx < max">
      <td style="width: 5%;"><div class="shimmerBG content-line"></div></td>
      <td style="width: 20%;"><div class="shimmerBG content-line"></div></td>
      <td style="width: 30%;"><div class="shimmerBG content-line"></div></td>
      <td style="width: 20%;"><div class="shimmerBG content-line"></div></td>
      <td style="width: 10%;"><div class="shimmerBG content-line"></div></td>
      <td style="width: 15%;"><div class="shimmerBG content-line"></div></td>
    </tr>
  </table>

  <div v-if="loading && type == 'loader' " class="text-center py-4">
    <span class="spinner-border text-primary"></span>
  </div>
</template>

<style scoped>
  .shimmerBG {
    animation-duration: 2.2s;
    animation-fill-mode: forwards;
    animation-iteration-count: infinite;
    animation-name: shimmer;
    animation-timing-function: linear;
    background: #ddd;
    background: linear-gradient(to right, #F6F6F6 8%, #F0F0F0 18%, #F6F6F6 33%);
    background-size: 1200px 100%;
  }


  @-webkit-keyframes shimmer {
    0% {
        background-position: -100% 0;
    }
    100% {
        background-position: 100% 0;
    }
  }

  @keyframes shimmer {
    0% {
        background-position: -1200px 0;
    }
    100% {
        background-position: 1200px 0;
    }
  }

  .content-line {
    margin-top: 10px;
    height: 10px;
    width: 100%;
    margin-bottom: 16px;
    border-radius: 8px;
  }
</style>