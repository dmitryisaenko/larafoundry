<script setup>
/**
 * Operator profile hub — the super-admin's own account screens, tabbed, inside
 * the console shell.
 *
 * The operator equivalent of the tenant Profile hub: it reuses the SAME section
 * components (profile form, avatar, sessions, appearance/account settings) but
 * points each at the admin-namespaced endpoints (the operator is confined to
 * `admin.*`), and swaps the Security tab for {@see OperatorSecurity} (2FA + PIN +
 * password over the `admin.security.*` endpoints). No Danger zone — the operator
 * account is not self-deletable.
 *
 * The active tab is synced to the `?tab=` query (history.replaceState) so the
 * Security tab's server redirects (enable/confirm/password) land back on it.
 */
import { ref } from 'vue';
import { AdminLayout } from '@dmitryisaenko/larafoundry';
import ProfileForm from '../../Profile/sections/ProfileForm.vue';
import AvatarManager from '../../Profile/sections/AvatarManager.vue';
import SessionsManager from '../../Profile/sections/SessionsManager.vue';
import Appearance from '../../Profile/sections/Appearance.vue';
import OperatorSecurity from './sections/OperatorSecurity.vue';
// Relative (not the barrel): this page is published into host apps where the
// barrel is the package and does not re-export every leaf.
import SettingsForm from '../../../components/settings/SettingsForm.vue';

const props = defineProps({
    profile: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    uiSettings: { type: Object, default: () => ({}) },
    uiSettingsSchema: { type: Array, default: () => [] },
    accountSettings: { type: Object, default: () => ({ schema: [], values: {} }) },
    pin: { type: Object, default: () => ({ enabled: false, has_pin: false, length: 4 }) },
    two_factor_enabled: { type: Boolean, default: false },
    two_factor_setup: { type: Object, default: null },
    recovery_codes: { type: Array, default: null },
    can_manage_two_factor: { type: Boolean, default: false },
    initialTab: { type: String, default: 'profile' },
});

const tabs = [
    { key: 'profile', label: 'Profile' },
    { key: 'avatar', label: 'Photo' },
    { key: 'security', label: 'Security' },
    { key: 'sessions', label: 'Sessions' },
    { key: 'appearance', label: 'Preferences' },
];

const activeTab = ref(props.initialTab);

function selectTab(key) {
    activeTab.value = key;

    // Reflect the tab in the URL so a server redirect from the Security tab
    // (enable/confirm/password → back to this hub) reopens the right tab.
    if (typeof window !== 'undefined') {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        window.history.replaceState(window.history.state, '', url);
    }
}
</script>

<template>
    <AdminLayout :title="$t('Profile')">
        <div class="flex flex-col gap-6">
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
                    @click="selectTab(tab.key)"
                >
                    {{ $t(tab.label) }}
                </button>
            </nav>

            <div class="rounded-sm border border-border bg-surface p-6">
                <ProfileForm
                    v-if="activeTab === 'profile'"
                    :profile="profile"
                    endpoint="/admin/profile/information"
                    :email-editable="false"
                />

                <AvatarManager
                    v-else-if="activeTab === 'avatar'"
                    :profile="profile"
                    endpoint="/admin/profile/avatar"
                />

                <OperatorSecurity
                    v-else-if="activeTab === 'security'"
                    :two_factor_enabled="two_factor_enabled"
                    :two_factor_setup="two_factor_setup"
                    :recovery_codes="recovery_codes"
                    :can_manage_two_factor="can_manage_two_factor"
                    :has_pin="pin.has_pin"
                    :pin_length="pin.length"
                    :has_password="profile.has_password"
                />

                <SessionsManager
                    v-else-if="activeTab === 'sessions'"
                    :sessions="sessions"
                    endpoint="/admin/profile/sessions"
                />

                <div v-else-if="activeTab === 'appearance'" class="flex flex-col gap-8">
                    <Appearance :settings="uiSettings" :schema="uiSettingsSchema" />

                    <!-- Folded-in account settings (user-scope generic preferences).
                         Hidden when nothing is registered so the tab never shows an
                         empty block. Posts to the admin-namespaced clone. -->
                    <section v-if="accountSettings.schema.length" class="flex max-w-xl flex-col gap-3">
                        <header>
                            <h2 class="text-base font-semibold text-ink">{{ $t('Account') }}</h2>
                            <p class="text-sm text-ink-soft">{{ $t('Manage your account preferences.') }}</p>
                        </header>
                        <SettingsForm
                            :schema="accountSettings.schema"
                            :values="accountSettings.values"
                            endpoint="/admin/profile/account"
                        />
                    </section>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
