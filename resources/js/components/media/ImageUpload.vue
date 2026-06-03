<script setup>
/**
 * Image upload with live preview (phase 2.4).
 *
 * Builds on FileUpload: when an image is picked it shows a local preview via an
 * object URL (revoked on change/unmount, so no memory leak), plus an existing
 * image when one is already stored. Emits the File via v-model for the page to
 * submit. Restricts the picker to images by default.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FileUpload from './FileUpload.vue';

const props = defineProps({
    modelValue: { type: [Object, null], default: null },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    accept: { type: String, default: 'image/*' },
    currentUrl: { type: String, default: '' },
    previewSize: { type: Number, default: 96 },
});

const emit = defineEmits(['update:modelValue']);

const objectUrl = ref('');

function revoke() {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
        objectUrl.value = '';
    }
}

watch(
    () => props.modelValue,
    (file) => {
        revoke();
        if (file instanceof File && file.type.startsWith('image/')) {
            objectUrl.value = URL.createObjectURL(file);
        }
    },
);

onBeforeUnmount(revoke);

const previewUrl = computed(() => objectUrl.value || props.currentUrl);
const dimension = computed(() => `${props.previewSize}px`);

function onUpdate(file) {
    emit('update:modelValue', file);
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-if="previewUrl"
            class="overflow-hidden rounded-sm border border-border bg-surface-subtle"
            :style="{ width: dimension, height: dimension }"
        >
            <img :src="previewUrl" alt="" class="h-full w-full object-cover">
        </div>

        <FileUpload
            :model-value="modelValue"
            :label="label"
            :accept="accept"
            :error="error"
            @update:model-value="onUpdate"
        />
    </div>
</template>
