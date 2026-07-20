<script setup>
/**
 * Terms re-acceptance screen (phase 5.3 part B).
 *
 * Where the `larafoundry.terms` gate sends an authenticated user whose accepted
 * Terms version is stale. They can read the updated Terms, then accept to
 * continue to where they were headed — or log out. Posting records the current
 * version against their account.
 */
import { useForm, Link } from '@inertiajs/vue3';
import { AppBaseLayout, LogoutForm } from '@dmitryisaenko/larafoundry';

defineProps({
    version: { type: [Number, String], default: null },
    terms_url: { type: String, required: true },
});

const form = useForm({});

function accept() {
    form.post('/terms/accept', { preserveScroll: true });
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto flex w-full max-w-xl flex-col gap-5 rounded-md border border-border bg-surface p-6">
            <h1 class="text-2xl font-semibold text-ink">{{ $t('Our terms have changed') }}</h1>

            <p class="text-sm text-ink">
                {{ $t('Please review and accept the updated Terms of Service to continue.') }}
            </p>

            <Link
                :href="terms_url"
                class="text-sm text-brand-600 underline hover:text-brand-700"
            >
                {{ $t('Read the Terms of Service') }}
            </Link>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-50"
                    :disabled="form.processing"
                    @click="accept"
                >
                    {{ $t('Accept and continue') }}
                </button>
                <LogoutForm button-class="text-sm text-ink-soft hover:text-ink">
                    {{ $t('Log out') }}
                </LogoutForm>
            </div>
        </div>
    </AppBaseLayout>
</template>
