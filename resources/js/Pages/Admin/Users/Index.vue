<script setup>
import { computed, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator, UsersTable } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin user list (phase 2.3) — the first populated console screen.
 * Super-admin only (gated server-side by `larafoundry.admin`).
 *
 * Row actions go straight back to the admin endpoints; the server is the
 * authority (membership, impersonation policy, blocking). This page just wires
 * the buttons.
 */
const props = defineProps({
    users: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const rows = computed(() => props.users.data ?? []);

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    emailVerified: props.filters.emailVerified ?? '',
    locale: props.filters.locale ?? '',
    authType: props.filters.authType ?? '',
});

let debounce = null;
watch(form, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/admin/users', cleaned(), { preserveState: true, replace: true });
    }, 300);
});

function cleaned() {
    return Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
}

function edit(user) {
    router.get(`/admin/users/${user.id}/edit`);
}

function block(user) {
    router.post(`/admin/users/${user.id}/block`, {}, { preserveScroll: true });
}

function unblock(user) {
    router.post(`/admin/users/${user.id}/unblock`, {}, { preserveScroll: true });
}

function destroy(user) {
    router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
}

function restore(user) {
    router.post(`/admin/users/${user.id}/restore`, {}, { preserveScroll: true });
}

function impersonate(user) {
    router.post(`/admin/impersonate/${user.id}`);
}
</script>

<template>
    <AdminLayout :title="$t('Users')">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        v-model="form.search"
                        type="search"
                        :placeholder="$t('Search users')"
                        class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"
                    >
                    <select v-model="form.status" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('All statuses') }}</option>
                        <option value="active">{{ $t('Active') }}</option>
                        <option value="blocked">{{ $t('Blocked') }}</option>
                        <option value="deleted">{{ $t('Deleted') }}</option>
                    </select>
                    <select v-model="form.emailVerified" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('Any email') }}</option>
                        <option value="verified">{{ $t('Verified') }}</option>
                        <option value="unverified">{{ $t('Unverified') }}</option>
                    </select>
                    <select v-model="form.locale" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('Any language') }}</option>
                        <option value="en">EN</option>
                        <option value="uk">UK</option>
                    </select>
                    <select v-model="form.authType" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('Any auth') }}</option>
                        <option value="oauth">{{ $t('OAuth') }}</option>
                        <option value="password">{{ $t('Password') }}</option>
                    </select>
                </div>
                <button
                    type="button"
                    class="rounded-sm bg-brand-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
                    @click="router.get('/admin/users/create')"
                >
                    {{ $t('New user') }}
                </button>
            </div>

            <div class="rounded-sm border border-border bg-surface p-3">
                <UsersTable
                    :users="rows"
                    @edit="edit"
                    @block="block"
                    @unblock="unblock"
                    @delete="destroy"
                    @restore="restore"
                    @impersonate="impersonate"
                />
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
