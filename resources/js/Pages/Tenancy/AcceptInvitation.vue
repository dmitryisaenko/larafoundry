<script setup>
/**
 * Accept or decline a company invitation.
 *
 * The token is in the URL; the server verifies the logged-in user's email
 * matches the invited one AND is verified, so this page just presents the
 * choice. The invited email is intentionally not shown (the page is public and
 * the address must not leak to a token holder). Both actions POST to the token
 * routes.
 */
import { router } from '@inertiajs/vue3';
import { AuthCard, AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    token: { type: String, required: true },
    company: { type: String, required: true },
});

function accept() {
    router.post(`/invitations/${props.token}/accept`);
}

function reject() {
    router.post(`/invitations/${props.token}/reject`);
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard
            :title="$t('Join {company}', { company })"
            :subtitle="$t('You have been invited to join this company.')"
        >
            <div class="flex flex-col gap-3">
                <button
                    type="button"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600"
                    @click="accept"
                >
                    {{ $t('Accept invitation') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-border px-4 py-2 text-ink-soft transition hover:bg-surface-subtle"
                    @click="reject"
                >
                    {{ $t('Decline') }}
                </button>
            </div>
        </AuthCard>
    </AppBaseLayout>
</template>
