<script setup>
/**
 * Grouped permission picker.
 *
 * Renders the catalog (`modules` prop — the shape from PermissionCatalog::modules:
 * module => { label, permissions: { slug: description } }) as checkbox groups and
 * keeps a flat array of selected slugs via v-model. A per-module toggle selects or
 * clears a whole group. The server re-validates every slug against the catalog, so
 * this is a convenience, not the authority.
 */
import { computed } from 'vue';
import { useT } from '../composables/useT.js';

const t = useT();

const props = defineProps({
    modules: { type: Object, default: () => ({}) },
    modelValue: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const selected = computed(() => new Set(props.modelValue));

function toggle(slug) {
    const next = new Set(props.modelValue);
    next.has(slug) ? next.delete(slug) : next.add(slug);
    emit('update:modelValue', [...next]);
}

function moduleSlugs(module) {
    return Object.keys(module.permissions ?? {});
}

function allSelected(module) {
    const slugs = moduleSlugs(module);

    return slugs.length > 0 && slugs.every((slug) => selected.value.has(slug));
}

function toggleModule(module) {
    const slugs = moduleSlugs(module);
    const next = new Set(props.modelValue);

    if (allSelected(module)) {
        slugs.forEach((slug) => next.delete(slug));
    } else {
        slugs.forEach((slug) => next.add(slug));
    }

    emit('update:modelValue', [...next]);
}
</script>

<template>
    <div class="space-y-5">
        <fieldset
            v-for="(module, key) in modules"
            :key="key"
            class="rounded-sm border border-border p-3"
        >
            <legend class="flex items-center gap-2 px-1 text-sm font-semibold text-ink">
                <input
                    type="checkbox"
                    :checked="allSelected(module)"
                    @change="toggleModule(module)"
                />
                {{ module.label }}
            </legend>

            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label
                    v-for="(description, slug) in module.permissions"
                    :key="slug"
                    class="flex items-start gap-2 text-sm text-ink-soft"
                >
                    <input
                        type="checkbox"
                        :value="slug"
                        :checked="selected.has(slug)"
                        @change="toggle(slug)"
                    />
                    <span>
                        <span class="text-ink">{{ description }}</span>
                        <span class="block text-xs text-ink-soft">{{ slug }}</span>
                    </span>
                </label>
            </div>
        </fieldset>
    </div>
</template>
