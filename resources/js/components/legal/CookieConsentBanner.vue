<script setup>
/**
 * Cookie-consent banner (phase 5.3 part B).
 *
 * A host drops this into its layout once. It reads the core `consent.cookie`
 * shared prop (HandleInertiaRequests) and shows itself ONLY when the feature is
 * enabled and no decision has been recorded yet — so it stays hidden by default
 * (the core sets only strictly-necessary cookies) and disappears once the visitor
 * chooses. The choice is binary: accept all, or necessary only.
 *
 * Posting the decision drops an encrypted cookie (and, for a signed-in user, the
 * `cookie_consent` setting); the server then reports `decided: true`, so the
 * banner does not return.
 */
import { computed } from 'vue';
import { usePage, router, Link } from '@inertiajs/vue3';

const page = usePage();

const cookie = computed(() => page.props.consent?.cookie ?? { enabled: false, decided: true });

const visible = computed(() => cookie.value.enabled === true && cookie.value.decided !== true);

function decide(decision) {
    router.post('/consent/cookies', { decision }, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div
        v-if="visible"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-surface p-4 shadow-lg"
        role="region"
        :aria-label="$t('Cookie consent')"
    >
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-ink">
                {{ $t('We use cookies to keep this site working. You can accept all cookies or only the necessary ones.') }}
                <Link href="/legal/cookies" class="text-brand-600 underline hover:text-brand-700">
                    {{ $t('Cookie Policy') }}
                </Link>
            </p>

            <div class="flex shrink-0 gap-2">
                <button
                    type="button"
                    class="rounded-sm border border-border px-4 py-2 text-sm font-medium text-ink transition hover:bg-surface-muted"
                    @click="decide('necessary')"
                >
                    {{ $t('Necessary only') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600"
                    @click="decide('accept')"
                >
                    {{ $t('Accept all') }}
                </button>
            </div>
        </div>
    </div>
</template>
