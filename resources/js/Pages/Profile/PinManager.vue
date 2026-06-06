<script setup>
/**
 * PIN-lock management (profile).
 *
 * Lets a user set a PIN, lock the session on demand, or remove the PIN. Set and
 * remove POST to the core's `/pin/enable` and `/pin/disable`; "lock now" POSTs
 * to `/pin/lock` (locks every session). Removing requires the current PIN.
 */
import { useForm, router } from '@inertiajs/vue3';
import { InputField } from '@dmitryisaenko/larafoundry';

defineProps({
    hasPin: { type: Boolean, default: false },
    length: { type: Number, default: 4 },
});

const enableForm = useForm({ pin: '' });
const disableForm = useForm({ pin: '' });

function enable() {
    enableForm.post('/pin/enable', { onSuccess: () => enableForm.reset('pin') });
}

function disable() {
    disableForm.post('/pin/disable', { onSuccess: () => disableForm.reset('pin') });
}

function lockNow() {
    router.post('/pin/lock');
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <header>
            <h2 class="text-base font-semibold">{{ $t('PIN lock') }}</h2>
            <p class="text-sm text-ink-soft">
                {{ $t('Lock this session with a short PIN after inactivity, instead of a full re-login.') }}
            </p>
        </header>

        <form v-if="!hasPin" class="flex flex-col gap-3" @submit.prevent="enable">
            <InputField
                v-model="enableForm.pin"
                :title="$t('New PIN')"
                name="pin"
                type="password"
                :maxlength="length"
                :required="true"
                :errors="enableForm.errors"
                autocomplete="off"
                inputmode="numeric"
            />
            <button
                type="submit"
                :disabled="enableForm.processing"
                class="self-start rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
            >
                {{ $t('Set a PIN') }}
            </button>
        </form>

        <template v-else>
            <button
                type="button"
                class="self-start rounded-sm border border-ink-soft px-4 py-2 transition hover:bg-surface-soft"
                @click="lockNow"
            >
                {{ $t('Lock now') }}
            </button>

            <form class="flex flex-col gap-3" @submit.prevent="disable">
                <InputField
                    v-model="disableForm.pin"
                    :title="$t('Current PIN')"
                    name="pin"
                    type="password"
                    :maxlength="length"
                    :required="true"
                    :errors="disableForm.errors"
                    autocomplete="off"
                    inputmode="numeric"
                />
                <button
                    type="submit"
                    :disabled="disableForm.processing"
                    class="self-start rounded-sm bg-danger-500 px-4 py-2 text-white transition hover:bg-danger-600 disabled:opacity-50"
                >
                    {{ $t('Remove PIN') }}
                </button>
            </form>
        </template>
    </section>
</template>
