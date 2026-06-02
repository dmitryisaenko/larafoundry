<script setup>
/**
 * Create / edit a company role: name, description and its permission set.
 *
 * `role` is null when creating; otherwise it carries the current values. The
 * permission catalog (`modules`) drives the PermissionsSelector. Submits to the
 * authorization.roles store/update routes; the server fixes the role flags and
 * company scope and re-validates every permission slug.
 */
import { useForm } from '@inertiajs/vue3';
import { AppBaseLayout, InputField, TextareaField, PermissionsSelector } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    role: { type: Object, default: null },
    modules: { type: Object, default: () => ({}) },
});

const isEdit = !!props.role;

const form = useForm({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permissions: props.role?.permissions ? [...props.role.permissions] : [],
});

function submit() {
    if (isEdit) {
        form.put(`/roles/${props.role.id}`);
    } else {
        form.post('/roles');
    }
}
</script>

<template>
    <AppBaseLayout>
        <form class="mx-auto w-full max-w-3xl space-y-6 p-4" @submit.prevent="submit">
            <h1 class="text-xl font-semibold text-ink">
                {{ isEdit ? $t('Edit role') : $t('New role') }}
            </h1>

            <InputField
                v-model="form.name"
                :title="$t('Name')"
                name="name"
                :required="true"
                :errors="form.errors"
            />

            <TextareaField
                v-model="form.description"
                :title="$t('Description')"
                name="description"
                :errors="form.errors"
            />

            <div>
                <h2 class="mb-2 text-sm font-semibold text-ink">{{ $t('Permissions') }}</h2>
                <PermissionsSelector v-model="form.permissions" :modules="modules" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
            >
                {{ $t('Save') }}
            </button>
        </form>
    </AppBaseLayout>
</template>
