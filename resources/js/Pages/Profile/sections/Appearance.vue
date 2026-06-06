<script setup>
/**
 * Appearance / UI preferences (phase 5.1).
 *
 * Renders one control per registered ui_settings key (from the backend schema):
 * a select for an enum, a toggle for a boolean. Each change PUTs the single
 * key/value to the allowlisted endpoint — the server rejects anything not in the
 * registry, so this form can only touch declared preferences.
 */
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { SelectField } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    schema: { type: Array, default: () => [] },
});

const state = reactive({ ...props.settings });

// Keep the local controls in sync if the server returns fresh settings (e.g.
// after a non-preserved visit) — without this the optimistic state could drift.
watch(
    () => props.settings,
    (next) => Object.assign(state, next),
);

function save(key, value) {
    state[key] = value;
    router.put('/profile/ui-settings', { key, value }, { preserveScroll: true, preserveState: true });
}

function optionsFor(item) {
    return item.options.map((value) => ({ value, name: value }));
}
</script>

<template>
    <section class="flex max-w-xl flex-col gap-5">
        <header>
            <h2 class="text-base font-semibold text-ink">{{ $t('Appearance') }}</h2>
            <p class="text-sm text-ink-soft">{{ $t('Personalise how the interface looks for you.') }}</p>
        </header>

        <div v-for="item in schema" :key="item.key" class="flex flex-col gap-1">
            <SelectField
                v-if="item.type === 'string' && item.options.length"
                :model-value="state[item.key]"
                :name="item.key"
                :title="$t(item.key)"
                :value-name-array="optionsFor(item)"
                translate
                @update:model-value="(value) => save(item.key, value)"
            />

            <label v-else-if="item.type === 'boolean'" class="flex items-center gap-2 text-sm text-ink">
                <input
                    type="checkbox"
                    class="rounded-sm border-border"
                    :checked="state[item.key]"
                    @change="(event) => save(item.key, event.target.checked)"
                >
                {{ $t(item.key) }}
            </label>
        </div>
    </section>
</template>
