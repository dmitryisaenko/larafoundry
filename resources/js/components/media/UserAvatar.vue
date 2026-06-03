<script setup>
/**
 * User avatar (phase 2.4).
 *
 * Renders the resolved `avatar_url` the backend always provides — a stored
 * image, an external OAuth avatar, or a generated initials data-URI — so this
 * component never has to know which it is and never shows a broken image. If the
 * image still fails to load at runtime (e.g. a dead external URL), it falls back
 * to client-rendered initials so the slot is never blank.
 *
 * No v-html: the SVG default comes through `src` as a data-URI, not raw markup.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    src: { type: String, default: '' },
    name: { type: String, default: '' },
    size: { type: Number, default: 40 },
    alt: { type: String, default: '' },
});

const failed = ref(false);

const dimension = computed(() => `${props.size}px`);

const initials = computed(() => {
    const parts = props.name.trim().split(/\s+/).filter(Boolean);
    if (!parts.length) {
        return '?';
    }
    const first = parts[0]?.[0] ?? '';
    const second = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
    return (first + second).toUpperCase();
});

const showImage = computed(() => props.src !== '' && !failed.value);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-subtle text-xs font-semibold text-ink-soft"
        :style="{ width: dimension, height: dimension }"
    >
        <img
            v-if="showImage"
            :src="src"
            :alt="alt || name"
            class="h-full w-full object-cover"
            @error="failed = true"
        >
        <span v-else aria-hidden="true">{{ initials }}</span>
    </span>
</template>
