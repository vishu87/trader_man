<script setup>
    const {table_class=''} = defineProps(['table_class']);
</script>

<template>
    <div class="table-responsive">
        <table class="table" :class="table_class">
            <slot></slot>
        </table>
    </div>
</template>