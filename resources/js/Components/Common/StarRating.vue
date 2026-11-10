<script setup>
import { ref, computed } from 'vue';

// 1–5 stars. Click a star to set it; click the star that is already set to clear the rating (emits 0).
const props = defineProps({
    modelValue: { type: Number,  default: null },   // null / 0 = not rated
    disabled:   { type: Boolean, default: false },
    size:       { type: String,  default: '' },     // '' | 'lg'
    title:      { type: String,  default: '' },
});
const emit = defineEmits(['update:modelValue', 'rate']);

const hover = ref(0);
const shown = computed(() => (hover.value || props.modelValue || 0));

function pick(n) {
    if (props.disabled) return;
    const v = n === props.modelValue ? 0 : n;
    emit('update:modelValue', v || null);
    emit('rate', v);
}
</script>

<template>
    <span class="star-rating" :class="[size ? 'star-rating-' + size : '', { 'is-disabled': disabled }]" :title="title" @mouseleave="hover = 0">
        <button
            v-for="n in 5" :key="n" type="button" class="star-rating-btn" :class="{ on: n <= shown, preview: hover > 0 }" :disabled="disabled"
            :title="disabled ? title : (n === modelValue ? 'Clear the rating' : n + (n === 1 ? ' star' : ' stars'))"
            @mouseenter="hover = disabled ? 0 : n" @click.stop="pick(n)"
        ><i class="bi" :class="n <= shown ? 'bi-star-fill' : 'bi-star'"></i></button>
    </span>
</template>
