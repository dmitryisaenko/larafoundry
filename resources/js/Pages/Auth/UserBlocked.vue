<script setup>
/**
 * Account-blocked notice page.
 *
 * Static informational screen shown when an authenticated user's account has
 * been blocked. It targets no Fortify endpoint of its own — the only action is
 * logging out, which POSTs to `/logout`. Reason and date are surfaced when
 * provided.
 *
 * Props:
 *  - status {string|null} optional human-readable reason for the block
 *  - blockedAt {string|null} optional timestamp the block took effect
 */
import { Link } from '@inertiajs/vue3';
import AuthCard from '../../components/AuthCard.vue';
import AppBaseLayout from '../../layouts/AppBaseLayout.vue';

defineProps({
    status: { type: String, default: null },
    blockedAt: { type: String, default: null },
});
</script>

<template>
    <AppBaseLayout>
        <AuthCard>
            <div class="text-center">
                <h1 class="mb-2 text-xl font-semibold text-danger">{{ $t('Account blocked') }}</h1>
                <p class="mb-4 text-sm text-ink-soft">
                    {{ $t('Your account has been blocked. If you believe this is a mistake, please contact support.') }}
                </p>

                <dl v-if="status || blockedAt" class="mb-6 space-y-2 rounded-sm border border-border bg-surface-subtle p-4 text-left text-sm">
                    <div v-if="status" class="flex flex-col gap-0.5">
                        <dt class="font-medium text-ink">{{ $t('Reason') }}</dt>
                        <dd class="text-ink-soft">{{ status }}</dd>
                    </div>
                    <div v-if="blockedAt" class="flex flex-col gap-0.5">
                        <dt class="font-medium text-ink">{{ $t('Blocked at') }}</dt>
                        <dd class="text-ink-soft">{{ blockedAt }}</dd>
                    </div>
                </dl>

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600"
                >
                    {{ $t('Log out') }}
                </Link>
            </div>
        </AuthCard>
    </AppBaseLayout>
</template>
