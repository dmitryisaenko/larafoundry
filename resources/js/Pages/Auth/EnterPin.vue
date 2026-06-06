<script setup>
/**
 * PIN-entry (unlock) screen.
 *
 * Shown by CheckPinLock while the current session is locked. POSTs the PIN to
 * the core's `/pin/check` route, which unlocks THIS device on success and
 * forwards back to where the user was. The field length follows the configured
 * PIN length.
 */
import { useForm } from '@inertiajs/vue3';
import { InputField, AuthCard, AppBaseLayout } from '@dmitryisaenko/larafoundry';

defineProps({
    length: { type: Number, default: 4 },
});

const form = useForm({ pin: '' });

function submit() {
    form.post('/pin/check', {
        onFinish: () => form.reset('pin'),
    });
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard :title="$t('Enter your PIN')">
            <p class="mb-6 text-sm text-ink-soft">
                {{ $t('This session is locked. Enter your PIN to continue.') }}
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-model="form.pin"
                    :title="$t('PIN')"
                    name="pin"
                    type="password"
                    :maxlength="length"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="off"
                    inputmode="numeric"
                />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Unlock') }}
                </button>
            </form>
        </AuthCard>
    </AppBaseLayout>
</template>
