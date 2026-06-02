<script setup>
/**
 * Email-verification notice page.
 *
 * Shown to authenticated-but-unverified users. The "Resend" button POSTs to
 * Laravel Fortify's `/email/verification-notification` endpoint to dispatch a
 * fresh verification link; Fortify flashes `status === 'verification-link-sent'`
 * which we confirm to the user.
 *
 * Props:
 *  - status {string|null} session status flash
 */
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthCard from '../../components/AuthCard.vue';
import AppBaseLayout from '../../layouts/AppBaseLayout.vue';

defineProps({
    status: { type: String, default: null },
});

const sending = ref(false);

function resend() {
    router.post(
        '/email/verification-notification',
        {},
        {
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
        },
    );
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard
            :title="$t('Verify your email')"
            :subtitle="$t('Before continuing, please verify your email address by clicking the link we just sent you.')"
        >
            <p
                v-if="status === 'verification-link-sent'"
                class="mb-4 rounded-sm bg-brand-50 px-3 py-2 text-sm text-brand-700"
            >
                {{ $t('A new verification link has been sent to your email address.') }}
            </p>

            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    :disabled="sending"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                    @click="resend"
                >
                    {{ $t('Resend verification email') }}
                </button>

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="text-sm text-ink-soft hover:text-ink"
                >
                    {{ $t('Log out') }}
                </Link>
            </div>
        </AuthCard>
    </AppBaseLayout>
</template>
