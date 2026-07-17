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
// Imported by relative path, not the package barrel: this page IS published into
// host apps where the barrel is the package, and the barrel does not re-export
// every leaf — keeping it relative avoids a resolution gap.
import SettingsForm from '../../components/settings/SettingsForm.vue';

const props = defineProps({
    profile: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    uiSettings: { type: Object, default: () => ({}) },
    uiSettingsSchema: { type: Array, default: () => [] },
    canDeleteAccount: { type: Boolean, default: false },
    pin: { type: Object, default: () => ({ enabled: false, has_pin: false, length: 4 }) },
    // User-scope generic settings (e.g. email_notifications), folded into the
    // Preferences tab so there is no separate account-settings page.
    accountSettings: { type: Object, default: () => ({ schema: [], values: {} }) },
});

// Core controllers hand Inertia a JsonResource (ProfileResource); inertia-laravel
// resolves it wrapped in `data`, so normalise once here to a flat profile object
// (matches the admin operator hub) — each section then gets a plain object
// whatever the shape.
const profileData = computed(() => props.profile?.data ?? props.profile ?? {});

const tabs = computed(() => [
    { key: 'profile', label: 'Profile' },
    { key: 'avatar', label: 'Photo' },
    { key: 'security', label: 'Security' },
    { key: 'sessions', label: 'Sessions' },
    { key: 'appearance', label: 'Preferences' },
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
                <ProfileForm v-if="activeTab === 'profile'" :profile="profileData" />

                <AvatarManager v-else-if="activeTab === 'avatar'" :profile="profileData" />

                <div v-else-if="activeTab === 'security'" class="flex flex-col gap-8">
                    <PasswordForm v-if="profileData.has_password" />
                    <PinManager v-if="pin.enabled" :has-pin="pin.has_pin" :length="pin.length" />
                    <section>
                        <h2 class="text-base font-semibold text-ink">{{ $t('Two-factor authentication') }}</h2>
                        <p class="text-sm text-ink-soft">
                            {{ $t('Add a second step at sign-in with an authenticator app.') }}
                        </p>
                    </section>
                </div>

                <SessionsManager v-else-if="activeTab === 'sessions'" :sessions="sessions" />

                <div v-else-if="activeTab === 'appearance'" class="flex flex-col gap-8">
                    <Appearance :settings="uiSettings" :schema="uiSettingsSchema" />

                    <!-- Folded-in account settings (the old /settings page): the
                         user-scope generic preferences. Hidden when nothing is
                         registered so the tab never shows an empty block. -->
                    <section v-if="accountSettings.schema.length" class="flex max-w-xl flex-col gap-3">
                        <header>
                            <h2 class="text-base font-semibold text-ink">{{ $t('Account') }}</h2>
                            <p class="text-sm text-ink-soft">{{ $t('Manage your account preferences.') }}</p>
                        </header>
                        <SettingsForm
                            :schema="accountSettings.schema"
                            :values="accountSettings.values"
                            endpoint="/settings/account"
                        />
                    </section>
                </div>

                <DangerZone
                    v-else-if="activeTab === 'danger'"
                    :can-delete="canDeleteAccount"
                    :oauth-only="profileData.is_oauth_only"
                >
                    <DataExport />
                </DangerZone>
            </div>
        </div>
    </AppLayout>
</template>
