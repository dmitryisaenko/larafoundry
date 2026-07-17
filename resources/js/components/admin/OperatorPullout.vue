<script setup>
/**
 * Operator pull-out for the admin console shell.
 *
 * The super-admin equivalent of the tenant app's account pull-out: a right-hand
 * slide-over reached from an avatar button in the AdminLayout header. It carries
 * the operator's identity card plus the one-off shell actions the console lacked
 * — theme, language, a link to the operator profile hub, and log out.
 *
 * Self-contained: it owns its trigger and open state and reuses {@see
 * AdminFilterDrawer} as the slide-over shell (teleported overlay, reference-counted
 * scroll-lock, focus-trap, Esc), so AdminLayout only drops <OperatorPullout /> into
 * its header nav.
 *
 * Theme is SAVE-ONLY: toggling PUTs the `theme` ui-setting (the same allowlisted
 * endpoint the profile Appearance form uses) and the host applies it on the next
 * load. There is no core dark-mode application layer yet (that lands with the host
 * DarkMode work), so this deliberately does not flip a class live.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { UserAvatar } from '@dmitryisaenko/larafoundry';
import AdminFilterDrawer from './AdminFilterDrawer.vue';

const page = usePage();

const open = ref(false);
const showLocales = ref(false);

const user = computed(() => page.props.auth?.user ?? null);

// Current theme comes from the globally-shared ui_settings prop; save-only toggle
// flips between light and dark (a stored 'system'/unknown resolves toward dark).
const theme = computed(() => page.props.ui_settings?.theme ?? 'system');
const isDark = computed(() => theme.value === 'dark');

const locales = computed(() => page.props.available_locales ?? []);
const currentLocaleCode = computed(() => page.props.locale ?? null);
const currentLocale = computed(
    () => locales.value.find((l) => l.code === currentLocaleCode.value) ?? null,
);

function toggleTheme() {
    // Save-only: persist the preference; the host applies it on the next load.
    router.put(
        '/profile/ui-settings',
        { key: 'theme', value: isDark.value ? 'light' : 'dark' },
        { preserveScroll: true },
    );
}

function switchLocale(locale) {
    showLocales.value = false;
    if (locale.code === currentLocaleCode.value) {
        return;
    }
    router.post('/larafoundry/language', { locale: locale.code }, { preserveScroll: true });
}
</script>

<template>
    <div>
        <!-- Trigger: operator avatar in the header nav. -->
        <button
            type="button"
            class="flex items-center gap-2 rounded-full outline-none transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-brand-500"
            :aria-label="$t('Operator menu')"
            :title="$t('Operator menu')"
            @click="open = true"
        >
            <UserAvatar :src="user?.avatar_url" :name="user?.name" :size="32" />
        </button>

        <AdminFilterDrawer v-model:open="open" :title="$t('Operator')">
            <!-- Identity card -->
            <div class="flex items-center gap-3 rounded-md border border-border bg-surface-subtle p-4">
                <UserAvatar :src="user?.avatar_url" :name="user?.name" :size="40" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-ink">{{ user?.name }}</p>
                    <p class="truncate text-xs text-ink-faint">{{ user?.email }}</p>
                </div>
            </div>

            <nav class="flex flex-col gap-1 text-sm">
                <!-- Operator profile (profile / photo / security / sessions / preferences) -->
                <Link
                    href="/admin/profile"
                    class="flex items-center gap-3 rounded-md px-3 py-2.5 text-ink transition hover:bg-surface-subtle"
                    @click="open = false"
                >
                    <svg class="h-4.5 w-4.5 text-ink-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>{{ $t('Profile') }}</span>
                </Link>

                <!-- Language: inline expandable list (keeps the pull-out open). -->
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-ink transition hover:bg-surface-subtle"
                    :aria-label="$t('Language')"
                    @click="showLocales = !showLocales"
                >
                    <span v-if="currentLocale?.flag" class="text-base leading-none">{{ currentLocale.flag }}</span>
                    <svg v-else class="h-4.5 w-4.5 text-ink-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/></svg>
                    <span class="flex-1">{{ $t('Language') }}</span>
                    <span class="text-xs text-ink-faint">{{ currentLocale?.native }}</span>
                    <svg class="h-4 w-4 text-ink-faint transition-transform" :class="showLocales ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </button>
                <div v-show="showLocales" class="flex flex-col gap-0.5 pb-1 pl-9 pr-1">
                    <button
                        v-for="locale in locales"
                        :key="locale.code"
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-ink transition hover:bg-surface-subtle"
                        @click="switchLocale(locale)"
                    >
                        <span v-if="locale.flag" class="text-base leading-none">{{ locale.flag }}</span>
                        <span class="flex-1">{{ locale.native }}</span>
                        <svg v-if="locale.code === currentLocaleCode" class="h-4 w-4 text-brand-600 dark:text-brand-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                    </button>
                </div>

                <!-- Theme toggle (save-only). -->
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-ink transition hover:bg-surface-subtle"
                    :aria-label="isDark ? $t('Switch to light mode') : $t('Switch to dark mode')"
                    @click="toggleTheme"
                >
                    <svg v-if="isDark" class="h-4.5 w-4.5 text-ink-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/></svg>
                    <svg v-else class="h-4.5 w-4.5 text-ink-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                    <span>{{ isDark ? $t('Switch to light mode') : $t('Switch to dark mode') }}</span>
                </button>
            </nav>

            <template #footer>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="flex w-full items-center justify-center gap-2 rounded-md bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-950 dark:bg-brand-700 dark:hover:bg-brand-600"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                    <span>{{ $t('Log out') }}</span>
                </Link>
            </template>
        </AdminFilterDrawer>
    </div>
</template>
