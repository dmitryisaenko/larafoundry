<script>
/**
 * Forward stray attributes (autocomplete, inputmode, autofocus, aria-*) onto
 * the inner <input> rather than the wrapper, so password managers and mobile
 * keyboards see them. Without this, `inheritAttrs` would dump them on the root
 * <div> where they do nothing.
 */
export default { inheritAttrs: false };
</script>

<script setup>
/**
 * Labelled text input with inline validation error.
 *
 * Tailwind-styled against the core theme tokens. Keeps the legacy prop API
 * (title / name / errors / icon …) so host pages migrate without changes.
 * Two-way bound via `v-model`. Extra attributes (autocomplete, inputmode, …)
 * fall through to the inner <input>.
 */
const model = defineModel();

defineProps({
    title: { type: String, default: '' },
    name: { type: String, default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    icon: { type: String, default: '' },
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

        <div class="relative">
            <span v-if="icon" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-soft">
                <slot name="icon">{{ icon }}</slot>
            </span>
            <input
                v-model="model"
                :id="name"
                :name="name"
                :type="type"
                :placeholder="placeholder"
                :disabled="isDisabled"
                :readonly="readonly"
                :required="required"
                v-bind="$attrs"
                class="w-full rounded-sm border bg-surface px-3 py-2 text-ink transition outline-none placeholder:text-ink-faint focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:bg-surface-subtle"
                :class="[
                    errors[name] ? 'border-danger focus:border-danger focus:ring-danger-100' : 'border-border',
                    icon ? 'pl-9' : '',
                ]"
            />
        </div>

        <p v-if="errors[name]" class="text-xs text-danger">{{ errors[name] }}</p>
    </div>
</template>
