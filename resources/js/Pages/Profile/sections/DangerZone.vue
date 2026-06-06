<script setup>
/**
 * Danger zone — account deletion (phase 5.1), and the slot phase 5.3 grows into.
 *
 * Deleting requires the current password and is blocked server-side for a user
 * who still owns a company; `canDelete` mirrors that so the control hides when
 * deletion would be refused. The trailing <slot> is where phase 5.3 adds the
 * personal-data export (download) without reworking this section.
 */
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField } from '@dmitryisaenko/larafoundry';

defineProps({
    canDelete: { type: Boolean, default: false },
});

const confirming = ref(false);

const form = useForm({ current_password: '' });

function submit() {
    form.delete('/profile/account', {
        preserveScroll: true,
        onError: () => form.reset('current_password'),
    });
}
</script>

<template>
    <section class="flex max-w-xl flex-col gap-4">
        <header>
            <h2 class="text-base font-semibold text-danger">{{ $t('Delete account') }}</h2>
            <p class="text-sm text-ink-soft">
                {{ $t('Once your account is deleted, all of its resources and data will be removed.') }}
            </p>
        </header>

        <div class="rounded-sm border border-danger-200 bg-danger-50 p-4">
            <p v-if="!canDelete" class="text-sm text-ink">
                {{ $t('You still own one or more companies. Transfer or delete them before deleting your account.') }}
            </p>

            <template v-else>
                <button
                    v-if="!confirming"
                    type="button"
                    class="rounded-sm bg-danger-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-danger-700"
                    @click="confirming = true"
                >
                    {{ $t('Delete account') }}
                </button>

                <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                    <p class="text-sm text-ink">
                        {{ $t('Please enter your password to confirm you want to permanently delete your account.') }}
                    </p>
                    <InputField
                        v-model="form.current_password"
                        name="current_password"
                        type="password"
                        :title="$t('Current password')"
                        :errors="form.errors"
                        autocomplete="current-password"
                    />
                    <div class="flex gap-3">
                        <button
                            type="submit"
                            class="rounded-sm bg-danger-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-danger-700 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ $t('Permanently delete') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-sm border border-border px-4 py-2 text-sm transition hover:bg-surface-soft"
                            @click="confirming = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                    </div>
                </form>
            </template>
        </div>

        <!-- Phase 5.3 extension point: personal-data export lives here. -->
        <slot />
    </section>
</template>
