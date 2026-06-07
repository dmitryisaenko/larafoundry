<script setup>
/**
 * Profile hub (phase 5.1) — one page, tabbed over every self-service account
 * screen.
 *
 * Brings together what the core already shipped (password, 2FA, PIN, locale,
 * sessions) with the tabs this phase adds (profile form, avatar, appearance,
 * danger zone). The page only orchestrates tabs and hands each section its slice
 * of the backend props; every section owns its own form and endpoint.
 */
import { computed, ref } from 'vue';
import { AppLayout } from '@dmitryisaenko/larafoundry';
import ProfileForm from './sections/ProfileForm.vue';
import AvatarManager from './sections/AvatarManager.vue';
import PasswordForm from './sections/PasswordForm.vue';
import SessionsManager from './sections/SessionsManager.vue';
import Appearance from './sections/Appearance.vue';
import DangerZone from './sections/DangerZone.vue';
import DataExport from './sections/DataExport.vue';
import PinManager from './PinManager.vue';

const props = defineProps({
    profile: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    uiSettings: { type: Object, default: () => ({}) },
    uiSettingsSchema: { type: Array, default: () => [] },
    canDeleteAccount: { type: Boolean, default: false },
    pin: { type: Object, default: () => ({ enabled: false, has_pin: false, length: 4 }) },
});

const tabs = computed(() => [
    { key: 'profile', label: 'Profile' },
    { key: 'avatar', label: 'Photo' },
    { key: 'security', label: 'Security' },
    { key: 'sessions', label: 'Sessions' },
    { key: 'appearance', label: 'Appearance' },
    { key: 'danger', label: 'Danger zone' },
]);

const activeTab = ref('profile');
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-6">
            <h1 class="text-xl font-semibold text-ink">{{ $t('Profile') }}</h1>

            <nav class="flex flex-wrap gap-1 border-b border-border">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition"
                    :class="
                        activeTab === tab.key
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-ink-soft hover:text-ink'
                    "
                    @click="activeTab = tab.key"
                >
                    {{ $t(tab.label) }}
                </button>
            </nav>

            <div class="rounded-sm border border-border bg-surface p-6">
                <ProfileForm v-if="activeTab === 'profile'" :profile="profile" />

                <AvatarManager v-else-if="activeTab === 'avatar'" :profile="profile" />

                <div v-else-if="activeTab === 'security'" class="flex flex-col gap-8">
                    <PasswordForm v-if="profile.has_password" />
                    <PinManager v-if="pin.enabled" :has-pin="pin.has_pin" :length="pin.length" />
                    <section>
                        <h2 class="text-base font-semibold text-ink">{{ $t('Two-factor authentication') }}</h2>
                        <p class="text-sm text-ink-soft">
                            {{ $t('Add a second step at sign-in with an authenticator app.') }}
                        </p>
                    </section>
                </div>

                <SessionsManager v-else-if="activeTab === 'sessions'" :sessions="sessions" />

                <Appearance
                    v-else-if="activeTab === 'appearance'"
                    :settings="uiSettings"
                    :schema="uiSettingsSchema"
                />

                <DangerZone v-else-if="activeTab === 'danger'" :can-delete="canDeleteAccount">
                    <DataExport />
                </DangerZone>
            </div>
        </div>
    </AppLayout>
</template>
