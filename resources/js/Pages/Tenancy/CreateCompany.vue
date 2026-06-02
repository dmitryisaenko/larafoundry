<script setup>
/**
 * Company-creation wizard (3 steps, teams mode).
 *
 * Step 1 (basics) creates the company; steps 2 (logo) and 3 (invitations) enrich
 * it. There is no step 4 — plan/payment is billing (phase 3), not in the free
 * core. The starting step comes from the `step` prop (the controller resolves it
 * from the ?step query and the user's company state, so a reload resumes); the
 * local `current` ref advances it client-side as each step succeeds.
 *
 * UI-kit is imported from the package barrel (NOT relative paths) so publishing
 * the page into a host doesn't break the imports.
 */
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, TextareaField, AuthCard, AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    step: { type: Number, default: 1 },
});

const current = ref(props.step);

const step1 = useForm({ name: '', country: '', description: '' });
const step2 = useForm({ logo: null });
const step3 = useForm({ invitations: [''] });

function submitStep1() {
    step1.post('/companies/create/step1', {
        onSuccess: () => { current.value = 2; },
    });
}

function submitStep2() {
    step2.post('/companies/create/step2', {
        forceFormData: true,
        onSuccess: () => { current.value = 3; },
    });
}

function submitStep3() {
    step3
        .transform((data) => ({
            invitations: data.invitations.map((e) => e.trim()).filter(Boolean),
        }))
        .post('/companies/create/step3');
}

function addInvite() {
    step3.invitations.push('');
}

function removeInvite(index) {
    step3.invitations.splice(index, 1);
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard :title="$t('Create your company')">
            <!-- Step indicator (3 steps) -->
            <ol class="mb-6 flex items-center gap-2 text-xs">
                <li
                    v-for="n in 3"
                    :key="n"
                    class="flex-1 rounded-sm px-2 py-1 text-center"
                    :class="n === current ? 'bg-brand-500 text-white' : n < current ? 'bg-brand-50 text-brand-700' : 'bg-surface-subtle text-ink-soft'"
                >
                    {{ $t('Step') }} {{ n }}
                </li>
            </ol>

            <!-- Step 1: basics -->
            <form v-if="current === 1" class="flex flex-col gap-4" @submit.prevent="submitStep1">
                <InputField
                    v-model="step1.name"
                    :title="$t('Company name')"
                    name="name"
                    type="text"
                    :required="true"
                    :errors="step1.errors"
                />
                <InputField
                    v-model="step1.country"
                    :title="$t('Country')"
                    name="country"
                    type="text"
                    :errors="step1.errors"
                />
                <TextareaField
                    v-model="step1.description"
                    :title="$t('Description')"
                    name="description"
                    :errors="step1.errors"
                />
                <button
                    type="submit"
                    :disabled="step1.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Continue') }}
                </button>
            </form>

            <!-- Step 2: logo -->
            <form v-else-if="current === 2" class="flex flex-col gap-4" @submit.prevent="submitStep2">
                <label class="flex flex-col gap-1 text-sm font-medium text-ink">
                    {{ $t('Logo') }}
                    <input
                        type="file"
                        accept="image/*"
                        class="text-sm text-ink-soft"
                        @input="step2.logo = $event.target.files[0]"
                    >
                    <p v-if="step2.errors.logo" class="text-xs text-danger">{{ step2.errors.logo }}</p>
                </label>
                <button
                    type="submit"
                    :disabled="step2.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Continue') }}
                </button>
            </form>

            <!-- Step 3: invitations -->
            <form v-else class="flex flex-col gap-4" @submit.prevent="submitStep3">
                <div v-for="(email, index) in step3.invitations" :key="index" class="flex items-end gap-2">
                    <InputField
                        v-model="step3.invitations[index]"
                        :title="index === 0 ? $t('Invite teammates by email') : ''"
                        :name="`invitations.${index}`"
                        type="email"
                        placeholder="teammate@example.com"
                        :errors="step3.errors"
                        class="flex-1"
                    />
                    <button
                        v-if="step3.invitations.length > 1"
                        type="button"
                        class="rounded-sm border border-border px-3 py-2 text-sm text-ink-soft hover:bg-surface-subtle"
                        @click="removeInvite(index)"
                    >
                        {{ $t('Remove') }}
                    </button>
                </div>
                <button
                    type="button"
                    class="self-start text-sm text-brand-600 hover:text-brand-700"
                    @click="addInvite"
                >
                    + {{ $t('Add another') }}
                </button>
                <button
                    type="submit"
                    :disabled="step3.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Finish') }}
                </button>
            </form>
        </AuthCard>
    </AppBaseLayout>
</template>
