<script setup>
/**
 * Mobile navigation drawer (phase 2.3).
 *
 * A hamburger button that slides the same filtered menu tree in from the side.
 * Reuses {@see SidebarNav} for the tree itself, so the desktop and mobile menus
 * never drift. Closing on backdrop click and on navigation keeps it out of the
 * way once a link is followed.
 */
import { ref, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import SidebarNav from './SidebarNav.vue';

defineProps({
    items: { type: Array, default: () => [] },
});

const open = ref(false);

// Close the drawer once a navigation actually starts. router.on returns its
// unsubscribe fn; drop it on unmount so re-mounting the layout (shell swaps via
// LayoutSwitcher) does not stack stale listeners.
const stopOnStart = router.on('start', () => {
    open.value = false;
});

onUnmounted(stopOnStart);
</script>

<template>
    <div class="md:hidden">
        <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface p-2 text-ink-soft transition hover:bg-surface-subtle"
            :aria-expanded="open"
            :aria-label="$t('Toggle navigation')"
            @click="open = true"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>

        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-40">
                <div class="absolute inset-0 bg-black/40" @click="open = false" />
                <div class="absolute inset-y-0 left-0 w-72 max-w-[80%] overflow-y-auto bg-surface p-4 shadow-xl">
                    <div class="mb-4 flex justify-end">
                        <button
                            type="button"
                            class="rounded-sm p-2 text-ink-soft transition hover:bg-surface-subtle"
                            :aria-label="$t('Close')"
                            @click="open = false"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <SidebarNav :items="items" />
                </div>
            </div>
        </Teleport>
    </div>
</template>
