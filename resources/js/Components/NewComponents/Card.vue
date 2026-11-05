<script setup>
defineProps({
    title:        { type: String, default: '' },
    header_class: { type: String, default: '' },
});
</script>

<template>
    <div class="card">
        <div v-if="title || $slots.header_slot" class="card-header" :class="header_class">
            <span v-if="title" class="fw-semibold">{{ title }}</span>
            <slot name="header_slot"></slot>
        </div>
        <slot></slot>
    </div>
</template>
