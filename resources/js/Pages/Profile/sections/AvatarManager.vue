<script setup>
/**
 * Avatar upload / removal (phase 5.1).
 *
 * The upload posts a multipart form to the core's AvatarController (which stores
 * through the Media seam and resizes via the `avatar` variant); removal reverts
 * to the generated initials placeholder. The live preview comes from ImageUpload;
 * the saved avatar is shown by UserAvatar so the empty state is never blank.
 */
import { useForm, router } from '@inertiajs/vue3';
import { ImageUpload, UserAvatar } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    profile: { type: Object, required: true },
    // Where the avatar posts/deletes. Defaults to the tenant endpoint; the
    // operator hub points it at its admin-namespaced clone.
    endpoint: { type: String, default: '/profile/avatar' },
});

const form = useForm({ avatar: null });

function submit() {
    form.post(props.endpoint, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset('avatar'),
    });
}

function remove() {
    router.delete(props.endpoint, { preserveScroll: true });
}
</script>

<template>
    <section class="flex max-w-xl flex-col gap-4">
        <div class="flex items-center gap-4">
            <UserAvatar :src="profile.avatar_url" :name="profile.name" :size="72" />
            <div>
                <h2 class="text-base font-semibold text-ink">{{ $t('Profile photo') }}</h2>
                <p class="text-sm text-ink-soft">{{ $t('Upload a square image for the best result.') }}</p>
            </div>
        </div>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <ImageUpload
                v-model="form.avatar"
                :label="$t('Choose a photo')"
                :error="form.errors.avatar"
                :current-url="profile.avatar_url"
            />

            <div class="flex gap-3">
                <button
                    type="submit"
                    class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                    :disabled="form.processing || !form.avatar"
                >
                    {{ $t('Save photo') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-border px-4 py-2 text-sm transition hover:bg-surface-soft"
                    @click="remove"
                >
                    {{ $t('Remove photo') }}
                </button>
            </div>
        </form>
    </section>
</template>
