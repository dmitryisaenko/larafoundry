<script setup>
/**
 * Per-row action buttons for the super-admin user table (phase 2.3, extended 3a;
 * legacy-parity restyle phase 7).
 *
 * Pure UI: it shows the right actions for the row's state (block vs unblock,
 * delete vs restore, verify vs unverify) and emits intent to the parent, which
 * performs the request. The "Follow" (impersonate) action is offered only for an
 * active, non-admin user — the server policy is the real boundary (an admin
 * cannot be impersonated), this just keeps the UI honest.
 *
 * Rendered as a single non-wrapping row of compact, colour-coded icon buttons
 * with tooltips (the tall wrapping text-link list is gone) — matching the boot
 * kohana console. Each button carries an `aria-label` (the SVG is decorative), so
 * the icon-only row is announced correctly. Block still emits `block`; the page
 * opens the themed BlockUserDialog (we do NOT copy the legacy hidden-<select>
 * reason hack).
 *
 * Phase 3a actions kept: verify/unverify email (always) and phone (only when the
 * `phone` column is opted in), plus quick links to the user's activity log and to
 * opening a support ticket on their behalf.
 */
defineProps({
    user: { type: Object, required: true },
    // The opt-in columns switched on for this table; gates the phone actions.
    userColumns: { type: Array, default: () => [] },
});

defineEmits([
    'edit',
    'block',
    'unblock',
    'delete',
    'restore',
    'impersonate',
    'verify-email',
    'unverify-email',
    'verify-phone',
    'unverify-phone',
    'logs',
    'ticket',
]);

// Shared square icon-button base; colour variants are applied per action. Solid
// variants (white on a saturated 600) read on both themes; outline variants use
// an opacity hover wash + a dark: text variant so they don't wash out in dark.
const btn = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-sm transition';
const solid = {
    brand: 'bg-brand-600 text-white hover:bg-brand-700',
    emerald: 'bg-emerald-600 text-white hover:bg-emerald-700',
    amber: 'bg-amber-600 text-white hover:bg-amber-700',
    rose: 'bg-rose-600 text-white hover:bg-rose-700',
    slate: 'bg-slate-500 text-white hover:bg-slate-600',
};
const outline = {
    brand: 'border border-brand-500/60 text-brand-600 hover:bg-brand-500/10 dark:text-brand-300',
    amber: 'border border-amber-500/60 text-amber-600 hover:bg-amber-500/10 dark:text-amber-300',
    rose: 'border border-rose-500/60 text-rose-600 hover:bg-rose-500/10 dark:text-rose-300',
};
</script>

<template>
    <div class="flex flex-nowrap items-center justify-end gap-1.5">
        <!-- Create ticket on the user's behalf -->
        <button type="button" :class="[btn, outline.brand]" :aria-label="$t('Create ticket')" :title="$t('Create ticket')" @click="$emit('ticket')">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
        </button>

        <!-- Activity log -->
        <button type="button" :class="[btn, solid.emerald]" :aria-label="$t('Logs')" :title="$t('Logs')" @click="$emit('logs')">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
            </svg>
        </button>

        <!-- Edit (active users only) -->
        <button
            v-if="!user.is_blocked && !user.is_deleted"
            type="button"
            :class="[btn, solid.brand]"
            :aria-label="$t('Edit')"
            :title="$t('Edit')"
            @click="$emit('edit')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
            </svg>
        </button>

        <!-- Email verification override -->
        <button
            v-if="!user.email_verified"
            type="button"
            :class="[btn, outline.brand]"
            :aria-label="$t('Verify email')"
            :title="$t('Verify email')"
            @click="$emit('verify-email')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-10 5L2 7" />
            </svg>
        </button>
        <button
            v-else
            type="button"
            :class="[btn, outline.amber]"
            :aria-label="$t('Unverify email')"
            :title="$t('Unverify email')"
            @click="$emit('unverify-email')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-10 5L2 7" /><path d="m2 2 20 20" />
            </svg>
        </button>

        <!-- Phone verification override (only when the phone column is opted in) -->
        <template v-if="userColumns.includes('phone')">
            <button
                v-if="!user.phone_verified"
                type="button"
                :class="[btn, outline.brand]"
                :aria-label="$t('Verify phone')"
                :title="$t('Verify phone')"
                @click="$emit('verify-phone')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
                </svg>
            </button>
            <button
                v-else
                type="button"
                :class="[btn, outline.amber]"
                :aria-label="$t('Unverify phone')"
                :title="$t('Unverify phone')"
                @click="$emit('unverify-phone')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" /><path d="m2 2 20 20" />
                </svg>
            </button>
        </template>

        <!-- Impersonate: active, non-admin users only (server policy is the real gate) -->
        <button
            v-if="!user.is_admin && !user.is_blocked && !user.is_deleted"
            type="button"
            :class="[btn, solid.slate]"
            :aria-label="$t('Follow')"
            :title="$t('Follow')"
            @click="$emit('impersonate')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><path d="m10 17 5-5-5-5" /><path d="M15 12H3" />
            </svg>
        </button>

        <!-- Block / Unblock (block opens the themed reason dialog via the page) -->
        <button
            v-if="!user.is_blocked"
            type="button"
            :class="[btn, solid.amber]"
            :aria-label="$t('Block')"
            :title="$t('Block')"
            @click="$emit('block')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" /><path d="m4.9 4.9 14.2 14.2" />
            </svg>
        </button>
        <button
            v-else
            type="button"
            :class="[btn, outline.amber]"
            :aria-label="$t('Unblock')"
            :title="$t('Unblock')"
            @click="$emit('unblock')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" />
            </svg>
        </button>

        <!-- Delete / Restore -->
        <button
            v-if="!user.is_deleted"
            type="button"
            :class="[btn, solid.rose]"
            :aria-label="$t('Delete')"
            :title="$t('Delete')"
            @click="$emit('delete')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
            </svg>
        </button>
        <button
            v-else
            type="button"
            :class="[btn, outline.rose]"
            :aria-label="$t('Restore')"
            :title="$t('Restore')"
            @click="$emit('restore')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5" />
            </svg>
        </button>
    </div>
</template>
