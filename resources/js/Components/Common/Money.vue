<!-- <script setup>
const { amount } = defineProps(['amount'])
</script>

<template>
  <span>
    {{ new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(amount) }}
  </span>
</template> -->
<script setup>
const { amount } = defineProps(['amount'])

function formatIndianCurrency(value) {
    if (!value) return '₹ 0'

    if (value >= 10000000) {
        return '₹ ' + (value / 10000000).toFixed(1).replace(/\.0$/, '') + ' Cr'
    } 
    else if (value >= 100000) {
        return '₹ ' + (value / 100000).toFixed(0) + ' L'
    } 
    else if (value >= 1000) {
        return '₹ ' + (value / 1000).toFixed(0) + ' K'
    } 
    else {
        return '₹ ' + value
    }
}
</script>

<template>
  <span>
    {{ formatIndianCurrency(amount) }}
  </span>
</template>