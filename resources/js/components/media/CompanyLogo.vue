<script setup>
/**
 * Company logo (phase 2.4).
 *
 * Shows the company's `logo_url` when set; otherwise a small square mark with
 * the company initial, so a logo-less company still renders a stable, distinct
 * placeholder rather than empty space. Falls back to the initial on image error.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    src: { type: String, default: null },
    name: { type: String, default: '' },
    size: { type: Number, default: 32 },
});

const failed = ref(false);

const dimension = computed(() => `${props.size}px`);

const initial = computed(() => props.name.trim().charAt(0).toUpperCase() || '?');

const showImage = computed(() => !!props.src && !failed.value);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-sm bg-brand-50 text-xs font-bold text-brand-700"
        :style="{ width: dimension, height: dimension }"
    >
        <img
            v-if="showImage"
            :src="src"
            :alt="name"
            class="h-full w-full object-cover"
            @error="failed = true"
        >
        <span v-else aria-hidden="true">{{ initial }}</span>
    </span>
</template>
