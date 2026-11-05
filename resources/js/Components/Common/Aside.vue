<script setup>
defineProps({
    show:     { type: Boolean, required: true },
    title:    { type: String,  default: '' },
    subtitle: { type: String,  default: '' },
    size:     { type: String,  default: '' },   // 'sm' | '' | 'lg' | 'xl'
});

const emit = defineEmits(['close']);
</script>

<template>
    <teleport to="body">
        <!-- Overlay -->
        <transition name="fade-overlay">
            <div v-if="show" class="aside-overlay" @click="emit('close')"></div>
        </transition>

        <!-- Drawer -->
        <transition name="slide-aside">
            <div v-if="show" :class="['aside-drawer', size ? `aside-${size}` : '']">

                <!-- Header -->
                <div class="aside-header">
                    <div>
                        <p class="aside-title">{{ title }}</p>
                        <p v-if="subtitle" class="aside-subtitle">{{ subtitle }}</p>
                    </div>
                    <button class="aside-close" type="button" @click="emit('close')" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <!-- Scrollable body -->
                <div class="aside-body">
                    <slot></slot>
                </div>

                <!-- Optional sticky footer -->
                <div v-if="$slots.footer" class="aside-footer">
                    <slot name="footer"></slot>
                </div>

            </div>
        </transition>
    </teleport>
</template>
