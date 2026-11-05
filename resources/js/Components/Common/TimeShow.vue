<script setup>
const { time } = defineProps(['time'])

function convertDateTimeWithAMPM(datetimeStr) {
  if(!datetimeStr){
    return "";
  }
  var parts = datetimeStr.split(" ");
  var datePart = parts[0]; // "2025-01-01"
  var timePart = parts[1]; // "14:30:05"

  var dArr = datePart.split("-"); // ["2025", "01", "01"]
  var tArr = timePart.split(":"); // ["14", "30", "05"]

  var hour = parseInt(tArr[0], 10);
  var minute = tArr[1];
  var ampm = hour >= 12 ? "PM" : "AM";
  hour = hour % 12 || 12; // convert 0 to 12 for 12 AM

  var formattedDate = dArr[2] + "-" + dArr[1] + "-" + dArr[0].substring(2); // "01-01-25"
  var formattedTime = hour + ":" + minute + " " + ampm; // "2:30 PM"

  return formattedDate + " " + formattedTime; // "01-01-25 2:30 PM"
}


</script>

<template>
  <span>{{convertDateTimeWithAMPM(time)}}</span>
</template>