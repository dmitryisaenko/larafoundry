<script setup>
/**
 * Labelled date input with inline validation error.
 *
 * Uses the native date control to keep the core dependency-free — the legacy
 * host bundled `@vuepic/vue-datepicker`, which is too heavy to force on every
 * consumer. A host that wants a richer picker overrides this component.
 * Value is the ISO `yyyy-mm-dd` string the native input emits.
 */
const model = defineModel();

defineProps({
    title: { type: String, default: '' },
    name: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    min: { type: String, default: undefined },
    max: { type: String, default: undefined },
    isDisabled: { type: Boolean, default: false },
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

        <input
            v-model="model"
            :id="name"
            :name="name"
            type="date"
            :placeholder="placeholder"
            :min="min"
            :max="max"
            :disabled="isDisabled"
            :required="required"
            class="w-full rounded-sm border bg-surface px-3 py-2 text-ink transition outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:bg-surface-subtle"
            :class="errors[name] ? 'border-danger focus:border-danger focus:ring-danger-100' : 'border-border'"
        />

        <p v-if="errors[name]" class="text-xs text-danger">{{ errors[name] }}</p>
    </div>
</template>
