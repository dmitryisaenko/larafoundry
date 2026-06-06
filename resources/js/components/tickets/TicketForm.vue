<script setup>
/**
 * The fields shared by the user and operator "open a ticket" forms (phase 4.2):
 * title, message, priority and category selection. Bound to a passed-in Inertia
 * `useForm` instance so the parent owns submission and the redirect. The parent
 * adds its own extras (the operator's user picker + labels) via the default slot.
 */
import { computed } from 'vue';
import { InputField, SelectField, TextareaField } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    form: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const priorityOptions = computed(() =>
    props.priorities.map((slug) => ({ value: slug, name: `tickets.priority.${slug}` }))
);

function isChecked(slug) {
    return (props.form.categories ?? []).includes(slug);
}

function toggleCategory(slug) {
    const selected = new Set(props.form.categories ?? []);
    selected.has(slug) ? selected.delete(slug) : selected.add(slug);
    props.form.categories = [...selected];
}
</script>

<template>
    <div class="space-y-4">
        <InputField
            v-model="form.title"
            name="title"
            :title="$t('Title')"
            :errors="form.errors"
            required
        />

        <TextareaField
            v-model="form.message"
            name="message"
            :title="$t('Message')"
            :rows="6"
            :errors="form.errors"
            required
        />

        <SelectField
            v-model="form.priority"
            name="priority"
            :title="$t('Priority')"
            :value-name-array="priorityOptions"
            :errors="form.errors"
            translate
        />

        <div v-if="categories.length" class="flex flex-col gap-1">
            <span class="text-sm font-medium text-ink">{{ $t('Categories') }}</span>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="slug in categories"
                    :key="slug"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-sm border border-border px-3 py-1.5 text-sm transition"
                    :class="isChecked(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'text-ink-soft hover:bg-surface-accent'"
                >
                    <input
                        type="checkbox"
                        class="sr-only"
                        :checked="isChecked(slug)"
                        @change="toggleCategory(slug)"
                    />
                    {{ $t(`tickets.category.${slug}`) }}
                </label>
            </div>
            <p v-if="form.errors.categories" class="text-xs text-danger">{{ form.errors.categories }}</p>
        </div>

        <slot />
    </div>
</template>
