<script setup>
/**
 * Operator profile hub — the super-admin's own account screens, tabbed, inside
 * the console shell.
 *
 * The operator equivalent of the tenant Profile hub: it reuses the SAME section
 * components (profile form, avatar, sessions, appearance) but points each at the
 * admin-namespaced endpoints (the operator is confined to `admin.*`), and swaps
 * the Security tab for {@see OperatorSecurity} (2FA + PIN + password over the
 * `admin.security.*` endpoints). No Danger zone — the operator account is not
 * self-deletable. The Preferences tab shows only the core appearance keys (the
 * server trims ui_settings to theme/sidebar/date/time).
 *
 * The active tab is synced to the `?tab=` query (history.replaceState) so the
 * Security tab's server redirects (enable/confirm/password) land back on it.
 */
import { computed, ref } from 'vue';
import { AdminLayout } from '@dmitryisaenko/larafoundry';
import ProfileForm from '../../Profile/sections/ProfileForm.vue';
import AvatarManager from '../../Profile/sections/AvatarManager.vue';
import SessionsManager from '../../Profile/sections/SessionsManager.vue';
import Appearance from '../../Profile/sections/Appearance.vue';
import OperatorSecurity from './sections/OperatorSecurity.vue';

const props = defineProps({
    profile: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    uiSettings: { type: Object, default: () => ({}) },
    uiSettingsSchema: { type: Array, default: () => [] },
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

// Core controllers hand Inertia a JsonResource (ProfileResource); inertia-laravel
// resolves it wrapped in `data` (same as the tenant hub). Normalise once so each
// section gets a flat profile object, whatever the shape.
const profileData = computed(() => props.profile?.data ?? props.profile ?? {});

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
                    :profile="profileData"
                    endpoint="/admin/profile/information"
                    :email-editable="false"
                />

                <AvatarManager
                    v-else-if="activeTab === 'avatar'"
                    :profile="profileData"
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
                    :has_password="profileData.has_password"
                />

                <SessionsManager
                    v-else-if="activeTab === 'sessions'"
                    :sessions="sessions"
                    endpoint="/admin/profile/sessions"
                />

                <div v-else-if="activeTab === 'appearance'" class="flex flex-col gap-8">
                    <Appearance :settings="uiSettings" :schema="uiSettingsSchema" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
