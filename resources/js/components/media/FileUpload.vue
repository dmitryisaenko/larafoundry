<script setup>
/**
 * Generic file input (phase 2.4).
 *
 * A thin, styled wrapper over <input type="file"> that emits the chosen File via
 * v-model so a parent's useForm/FormData can submit it. Shows the selected file
 * name and any validation error passed down from the server. Does not upload by
 * itself — the page owns the request.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    modelValue: { type: [Object, null], default: null },
    label: { type: String, default: '' },
    accept: { type: String, default: '' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);

const fileName = computed(() => props.modelValue?.name ?? '');

function onChange(event) {
    const file = event.target.files?.[0] ?? null;
    emit('update:modelValue', file);
}

function clear() {
    if (input.value) {
        input.value.value = '';
    }
    emit('update:modelValue', null);
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="label" class="text-sm font-medium text-ink">{{ label }}</label>

        <div class="flex items-center gap-2">
            <input
                ref="input"
                type="file"
                :accept="accept"
                class="block w-full text-sm text-ink file:mr-3 file:rounded-sm file:border-0 file:bg-surface-subtle file:px-3 file:py-2 file:text-sm file:text-ink hover:file:bg-border"
                @change="onChange"
            >
            <button
                v-if="fileName"
                type="button"
                class="shrink-0 text-xs text-ink-soft underline"
                @click="clear"
            >
                {{ $t('Remove') }}
            </button>
        </div>

        <p v-if="error" class="text-xs text-rose-600">{{ error }}</p>
    </div>
</template>
