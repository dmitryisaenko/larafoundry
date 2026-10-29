<script setup>
/**
 * Company-blocked notice (sub-phase B7a) — one page, several modes.
 *
 * Both the core (super-admin block, via larafoundry.auth.blocked_redirect_route) and the
 * add-on's billing.access middleware (owner-ban / hard payment-required) redirect here
 * with a `type`. The controller validates `type` against a fixed set (it is null for an
 * unknown value, never reflected raw), so this page only maps a known mode to a message
 * and decides whether to offer the pay link. Logout posts to the literal /logout so it
 * works even when every named route is gated.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AppBaseLayout, AuthCard } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    blockType: { type: String, default: null },
    blockStatus: { type: Object, default: null },
    companyName: { type: String, default: null },
    pricingRoute: { type: String, default: 'billing.pricing' },
});

const MODES = {
    payment_required: {
        title: 'Subscription required',
        text: "This company's subscription has expired. Renew it to keep working.",
        showPay: true,
        danger: false,
    },
    payment_required_non_owner: {
        title: 'Subscription required',
        text: "This company's subscription has expired. Please contact the company owner to renew it.",
        showPay: false,
        danger: false,
    },
    owner_banned: {
        title: 'Company unavailable',
        text: "This company is unavailable because the owner's account is blocked. Please contact support.",
        showPay: false,
        danger: true,
    },
    company_blocked_admin: {
        title: 'Company suspended',
        text: 'This company has been suspended by an administrator. Please contact support.',
        showPay: false,
        danger: true,
    },
    user_banned: {
        title: 'Account blocked',
        text: 'Your account has been blocked. Please contact support.',
        showPay: false,
        danger: true,
    },
};

const FALLBACK = {
    title: 'Company unavailable',
    text: 'This company is currently unavailable.',
    showPay: false,
    danger: true,
};

const mode = computed(() => MODES[props.blockType] ?? FALLBACK);
</script>

<template>
    <AppBaseLayout>
        <AuthCard>
            <div class="text-center">
                <h1 class="mb-2 text-xl font-semibold" :class="mode.danger ? 'text-danger' : 'text-amber-700'">
                    {{ $t(mode.title) }}
                </h1>

                <p v-if="companyName" class="mb-2 text-sm font-medium text-ink">{{ companyName }}</p>

                <p class="mb-6 text-sm text-ink-soft">{{ $t(mode.text) }}</p>

                <div class="flex flex-col gap-2">
                    <Link
                        v-if="mode.showPay"
                        :href="route(pricingRoute)"
                        class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600"
                    >
                        {{ $t('Renew subscription') }}
                    </Link>

                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="rounded-sm border border-border px-4 py-2 text-ink-soft transition hover:bg-surface-subtle"
                    >
                        {{ $t('Log out') }}
                    </Link>
                </div>
            </div>
        </AuthCard>
    </AppBaseLayout>
</template>
