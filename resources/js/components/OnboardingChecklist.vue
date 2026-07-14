<script setup>
/**
 * Getting-started checklist (phase 5.4).
 *
 * A GENTLE, dismissible card the host places on its own home page — never a
 * blocking wizard. It reads the `onboarding` shared Inertia prop (built and
 * tallied on the backend) and shows the remaining steps with a small progress
 * indicator. Each step carries a check state, an i18n title/description and an
 * optional CTA link to where the user finishes it.
 *
 * Renders NOTHING when there is nothing to nudge: the prop is absent (host has
 * not wired it), the user has dismissed it, everything is done, or there are no
 * steps at all (personal mode once identity steps are complete). So mounting it
 * is always safe and nothing is ever forced on the user.
 *
 * The prop is read defensively (mirroring <Seo>), so a page that has not
 * serialised it degrades to an empty render rather than an error.
 */
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();

const onboarding = computed(() => page.props?.onboarding ?? null);

// The single gate for whether the card shows at all — kept in one place so the
// "never force it" rules cannot drift between checks.
const visible = computed(() => {
    const o = onboarding.value;

    return (
        o !== null &&
        o.dismissed !== true &&
        o.all_complete !== true &&
        (o.total ?? 0) > 0
    );
});

const steps = computed(() => onboarding.value?.steps ?? []);

function dismiss() {
    router.post('/onboarding/dismiss', {}, { preserveScroll: true });
}
</script>

<template>
    <section
        v-if="visible"
        class="rounded-sm border border-border bg-surface p-5 text-ink"
        :aria-label="$t('Getting started')"
    >
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">{{ $t('Getting started') }}</h2>
                <p
                    class="mt-0.5 text-sm text-ink-soft"
                    :aria-label="$t('Setup progress')"
                >
                    {{ onboarding.completed }} / {{ onboarding.total }}
                </p>
            </div>

            <button
                type="button"
                class="rounded-sm px-2 py-1 text-sm text-ink-soft transition hover:text-ink"
                @click="dismiss"
            >
                {{ $t('Hide') }}
            </button>
        </header>

        <ul class="space-y-3">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex items-start gap-3"
            >
                <span
                    class="mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full"
                    :class="step.complete ? 'bg-brand-50 text-brand-700' : 'border border-border text-ink-soft'"
                    aria-hidden="true"
                >
                    <svg
                        v-if="step.complete"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        class="h-3.5 w-3.5"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.6a1 1 0 0 1-1.42.006l-3.5-3.5a1 1 0 1 1 1.414-1.414l2.79 2.79 6.796-6.884a1 1 0 0 1 1.414-.008Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <p
                        class="text-sm font-medium"
                        :class="step.complete ? 'text-ink-soft line-through' : 'text-ink'"
                    >
                        {{ $t(step.title) }}
                    </p>
                    <p class="mt-0.5 text-sm text-ink-soft">{{ $t(step.description) }}</p>
                </div>

                <Link
                    v-if="!step.complete && step.cta_url"
                    :href="step.cta_url"
                    class="flex-none rounded-sm bg-brand-50 px-3 py-1 text-sm font-medium text-brand-700 transition hover:bg-brand-100"
                >
                    {{ $t(step.cta_label) }}
                </Link>
            </li>
        </ul>
    </section>
</template>
