<script setup>
/**
 * "You are impersonating another user" banner (phase 2.3).
 *
 * Shows only while the session is impersonating (the `impersonating` shared
 * prop, set by the backend from the session). A click POSTs to leave, which
 * restores the operator account server-side. Drop it once near the top of the
 * host layout; it self-hides when not impersonating.
 */
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

const page = usePage();

const active = computed(() => page.props.impersonating === true);

function leave() {
    router.post('/impersonate/leave');
}
</script>

<template>
    <div
        v-if="active"
        class="flex items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm text-amber-950"
    >
        <span class="font-medium">{{ $t('You are impersonating another user.') }}</span>
        <button
            type="button"
            class="rounded-sm bg-amber-950/10 px-3 py-1 font-semibold transition hover:bg-amber-950/20"
            @click="leave"
        >
            {{ $t('Stop impersonating') }}
        </button>
    </div>
</template>
