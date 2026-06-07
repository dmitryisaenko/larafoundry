<script setup>
/**
 * Schema-driven settings form (phase 5.1).
 *
 * Renders one control per registered key from the backend schema — a select for
 * an enum, a toggle for a boolean, a text/number input otherwise — and PUTs the
 * single changed key/value to the given endpoint. The server re-validates against
 * the registry, so this form can only ever touch declared, in-scope keys. Reused
 * by the account, company and app settings pages.
 */
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { InputField, SelectField } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    schema: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    endpoint: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});

const state = reactive({ ...props.values });

watch(
    () => props.values,
    (next) => Object.assign(state, next),
);

function save(key, value) {
    state[key] = value;
    router.put(props.endpoint, { key, value }, { preserveScroll: true, preserveState: true });
}

function optionsFor(item) {
    return item.options.map((value) => ({ value, name: value }));
}
</script>

<template>
    <div class="flex max-w-xl flex-col gap-5">
        <p v-if="!schema.length" class="text-sm text-ink-soft">
            {{ $t('There are no settings to manage here yet.') }}
        </p>

        <div v-for="item in schema" :key="item.key" class="flex flex-col gap-1">
            <SelectField
                v-if="item.type === 'string' && item.options.length"
                :model-value="state[item.key]"
                :name="item.key"
                :title="$t(item.label)"
                :value-name-array="optionsFor(item)"
                :is-disabled="!canEdit"
                translate
                @update:model-value="(value) => save(item.key, value)"
            />

            <label v-else-if="item.type === 'boolean'" class="flex items-center gap-2 text-sm text-ink">
                <input
                    type="checkbox"
                    class="rounded-sm border-border"
                    :checked="state[item.key]"
                    :disabled="!canEdit"
                    @change="(event) => save(item.key, event.target.checked)"
                >
                {{ $t(item.label) }}
            </label>

            <!-- Text/number: bind locally and save on blur, not per keystroke. -->
            <InputField
                v-else
                v-model="state[item.key]"
                :name="item.key"
                :title="$t(item.label)"
                :type="item.type === 'integer' || item.type === 'float' ? 'number' : 'text'"
                :is-disabled="!canEdit"
                @blur="save(item.key, state[item.key])"
            />
        </div>
    </div>
</template>
