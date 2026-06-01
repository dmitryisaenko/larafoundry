<script setup>
/**
 * Labelled textarea with inline validation error.
 *
 * Mirrors {@link InputField} styling and prop API for a multi-line value.
 */
const model = defineModel();

defineProps({
    title: { type: String, default: '' },
    name: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    rows: { type: Number, default: 4 },
    isDisabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="title" :for="name" class="text-sm font-medium text-ink">
            {{ title }}
            <span v-if="required" class="text-danger">*</span>
        </label>

        <textarea
            v-model="model"
            :id="name"
            :name="name"
            :rows="rows"
            :placeholder="placeholder"
            :disabled="isDisabled"
            :readonly="readonly"
            :required="required"
            class="w-full resize-y rounded-sm border bg-surface px-3 py-2 text-ink transition outline-none placeholder:text-ink-faint focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:bg-surface-subtle"
            :class="errors[name] ? 'border-danger focus:border-danger focus:ring-danger-100' : 'border-border'"
        />

        <p v-if="errors[name]" class="text-xs text-danger">{{ errors[name] }}</p>
    </div>
</template>
