<script setup>
/**
 * Block-with-reason dialog for the super-admin user table (phase 3a).
 *
 * Built on the core {@link Modal} (Teleport, scroll-lock, Esc/backdrop dismissal,
 * focus-trap) rather than a native `prompt()`/`confirm()`, so it is theme-native
 * and can carry a proper textarea. The parent owns the `open` state and performs
 * the request on `submit`; this component only collects the reason (and optional
 * numeric block code) and emits it.
 *
 * The backend clamps `block_code` to 0-255 and treats 0/out-of-range as "no code"
 * (UserController::block), so a stray value here is harmless — the input is only a
 * convenience.
 */
import { ref, watch } from 'vue';
import Modal from '../ui/Modal.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    user: { type: Object, default: null },
});

const emit = defineEmits(['submit', 'close']);

const reason = ref('');
const blockCode = ref('');

// Reset the fields each time the dialog opens so a previous, cancelled attempt
// never leaks its reason into the next user's block.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            reason.value = '';
            blockCode.value = '';
        }
    },
);

function submit() {
    emit('submit', {
        reason: reason.value.trim(),
        // An empty field submits no code; the backend defaults/clears it.
        block_code: blockCode.value === '' ? null : Number(blockCode.value),
    });
}

function cancel() {
    emit('close');
}
</script>

<template>
    <Modal :open="open" :title="$t('Block user')" @close="cancel">
        <div class="space-y-4">
            <p v-if="user" class="text-sm text-ink-soft">
                {{ user.name }} {{ user.lastname }}
                <span class="text-ink-faint">({{ user.email }})</span>
            </p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-ink" for="block-reason">{{ $t('Block reason') }}</label>
                <textarea
                    id="block-reason"
                    v-model="reason"
                    rows="3"
                    class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                ></textarea>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-ink" for="block-code">{{ $t('Block code') }}</label>
                <input
                    id="block-code"
                    v-model="blockCode"
                    type="number"
                    min="0"
                    max="255"
                    class="w-32 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                >
            </div>
        </div>

        <template #footer>
            <div class="flex justify-center gap-3">
                <button
                    type="button"
                    class="inline-flex h-10 min-w-24 items-center justify-center rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink-soft transition hover:bg-surface-subtle"
                    @click="cancel"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="inline-flex h-10 min-w-24 items-center justify-center rounded-md border border-amber-600 bg-amber-600 px-4 text-sm font-medium text-white transition hover:opacity-90"
                    @click="submit"
                >
                    {{ $t('Block') }}
                </button>
            </div>
        </template>
    </Modal>
</template>
