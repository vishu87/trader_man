<script setup>
const { date } = defineProps(['date'])

function reformatDate(dateStr) {
  if(!dateStr) {
    return ""; 
  }
  var dArr = dateStr.split("-");  // ex input: "2010-01-18"
  // return dArr[2]+ "-" +dArr[1]+ "-" +dArr[0].substring(2); //ex output: "18/01/10"
  return dArr[2]+ "-" +dArr[1]+ "-" +dArr[0]; //ex output: "18/01/10"
}

</script>

<template>
  <span>{{reformatDate(date)}}</span>
</template>