<script setup>
/**
 * Presentation-aware shell for the guest auth pages.
 *
 * One auth page (Login/Register/Forgot/Reset) renders the SAME body either as a
 * full page or as an overlay modal, decided by the `auth.presentation` config
 * the backend shares as the `auth_presentation` Inertia prop (page | modal).
 * The page never branches: it wraps its form in <AuthScreen> and this component
 * picks the surface. Default is `page`, so a host that sets nothing keeps the
 * current behaviour unchanged.
 *
 *  - page  : AppBaseLayout → AuthCard (the existing surface, unchanged — same
 *            wrapper, same props, so a host on the default keeps today's look).
 *  - modal : a centred Modal over the host's content. On a direct visit to e.g.
 *            /login the modal is simply open on an otherwise empty backdrop;
 *            the host can also open it over its own landing page.
 *
 * Props:
 *  - title, subtitle, status  forwarded to AuthCard / Modal heading.
 *  - open   {boolean} modal-mode visibility (default true — a direct auth visit
 *           shows the form immediately). Inert in page mode.
 *
 * Emits:
 *  - close  bubbled from the modal so the page can react (e.g. close on success).
 *
 * Slots: default (form body), footer (secondary links).
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppBaseLayout from '../../layouts/AppBaseLayout.vue';
import AuthCard from '../AuthCard.vue';
import Modal from '../ui/Modal.vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    status: { type: String, default: null },
    open: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const page = usePage();

// Resolve the presentation mode defensively: anything other than the known
// 'modal' value (including a missing prop on a host that doesn't share it) maps
// to 'page', so the default surface can never break.
const presentation = computed(() =>
    page.props?.auth_presentation === 'modal' ? 'modal' : 'page',
);
</script>

<template>
    <Modal
        v-if="presentation === 'modal'"
        :open="open"
        :title="title"
        @close="emit('close')"
    >
        <p v-if="subtitle" class="-mt-2 mb-4 text-sm text-ink-soft">{{ subtitle }}</p>

        <p v-if="status" class="mb-4 rounded-sm bg-brand-50 px-3 py-2 text-sm text-brand-700">
            {{ status }}
        </p>

        <slot />

        <template v-if="$slots.footer" #footer>
            <slot name="footer" />
        </template>
    </Modal>

    <AppBaseLayout v-else>
        <AuthCard :title="title" :subtitle="subtitle" :status="status">
            <slot />

            <template v-if="$slots.footer" #footer>
                <slot name="footer" />
            </template>
        </AuthCard>
    </AppBaseLayout>
</template>
