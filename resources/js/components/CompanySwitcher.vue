<script setup>
/**
 * Active-company switcher.
 *
 * Lists the companies shared by the core (`companies` Inertia prop) and PUTs a
 * switch to the chosen one. The server enforces membership, so a tampered uuid
 * is rejected there — this component is a convenience, not the security boundary.
 *
 * Reads its data from props the host shares via LaraFoundryTenancy::sharedProps,
 * so it works on any tenant-aware page without extra wiring.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useT } from '../composables/useT.js';

const t = useT();

const props = defineProps({
    companies: { type: Array, default: () => [] },
    active: { type: Object, default: null },
});

const open = ref(false);

const activeName = computed(() => props.active?.name ?? t('No company'));

function switchTo(company) {
    open.value = false;

    if (company.uuid === props.active?.uuid) {
        return;
    }

    router.put(`/companies/${company.uuid}/switch`, {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="flex items-center gap-2 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink transition hover:bg-surface-subtle"
            @click="open = !open"
        >
            <span class="font-medium">{{ activeName }}</span>
            <span class="text-ink-soft">▾</span>
        </button>

        <ul
            v-if="open && companies.length"
            class="absolute z-10 mt-1 w-56 overflow-hidden rounded-sm border border-border bg-surface shadow-md"
        >
            <li v-for="company in companies" :key="company.uuid">
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-ink transition hover:bg-surface-subtle"
                    :class="company.uuid === active?.uuid ? 'bg-brand-50 text-brand-700' : ''"
                    @click="switchTo(company)"
                >
                    <span>{{ company.name }}</span>
                    <span v-if="company.is_owner" class="text-xs text-ink-soft">{{ t('Owner') }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
