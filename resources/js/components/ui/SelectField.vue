<script setup>
import { computed } from 'vue';
import { useT } from '../../composables/useT.js';

/**
 * Labelled select with optional per-option translation.
 *
 * Options come as `valueNameArray` of `{ value, name }`. When `translate` is
 * set, each option name is passed through i18n. Uses the injectable `useT()`
 * rather than a global, so the component carries no hidden dependency on the
 * host's setup.
 */
const t = useT();
const model = defineModel();

const props = defineProps({
    valueNameArray: { type: Array, default: () => [] },
    title: { type: String, default: '' },
    name: { type: String, default: '' },
    defaultName: { type: String, default: '' },
    isDisabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    translate: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const options = computed(() => {
    if (!Array.isArray(props.valueNameArray)) {
        return [];
    }

    return props.valueNameArray.map((option) => {
        if (!option || typeof option !== 'object') {
            return { value: '', name: '' };
        }

        let name = option.name;
        if (props.translate && typeof name === 'string' && name.trim()) {
            name = t(name);
        }

        return { value: option.value, name };
    });
});
</script>

<template>
    <div class="flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <label v-if="title" :for="name" class="text-sm font-medium text-ink">
                {{ title }}
                <span v-if="required" class="text-danger">*</span>
            </label>
            <slot name="hint" />
        </div>

        <select
            v-model="model"
            :id="name"
            :name="name"
            :disabled="isDisabled"
            :required="required"
            class="w-full rounded-sm border bg-surface px-3 py-2 text-ink transition outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:bg-surface-subtle"
            :class="errors[name] ? 'border-danger focus:border-danger focus:ring-danger-100' : 'border-border'"
        >
            <option v-if="defaultName" value="">--- {{ defaultName }} ---</option>
            <option v-for="option in options" :key="option.value" :value="option.value">
                {{ option.name }}
            </option>
        </select>

        <p v-if="errors[name]" class="text-xs text-danger">{{ errors[name] }}</p>
    </div>
</template>
