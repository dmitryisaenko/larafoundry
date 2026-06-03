<script setup>
/**
 * Super-admin user list table (phase 2.3).
 *
 * Renders the rows shaped by AdminUserResource and emits row actions up to the
 * page (edit/block/unblock/delete/restore/impersonate), which own the requests.
 * No social links are shown — the resource omits them (finding #6, PII). No
 * v-html anywhere.
 */
import UsersTableActions from './UsersTableActions.vue';

defineProps({
    users: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">{{ $t('Name') }}</th>
                    <th class="px-3 py-2">{{ $t('Email') }}</th>
                    <th class="px-3 py-2">{{ $t('Country') }}</th>
                    <th class="px-3 py-2">{{ $t('Companies') }}</th>
                    <th class="px-3 py-2">{{ $t('Registered') }}</th>
                    <th class="px-3 py-2">{{ $t('Last activity') }}</th>
                    <th class="px-3 py-2">{{ $t('Status') }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="user in users" :key="user.id" class="border-b border-border/60 align-middle">
                    <td class="px-3 py-2">
                        <div class="font-medium text-ink">{{ user.name }} {{ user.lastname }}</div>
                        <div v-if="user.is_admin" class="text-xs font-semibold text-brand-700">{{ $t('Super-admin') }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <span>{{ user.email }}</span>
                        <span v-if="user.email_verified" class="ml-1 text-xs text-emerald-600" :title="$t('Verified')">✓</span>
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.country || '—' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.companies_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.registered_date || '—' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.last_activity_human || '—' }}</td>
                    <td class="px-3 py-2">
                        <span v-if="user.is_deleted" class="rounded-sm bg-rose-50 px-2 py-0.5 text-xs text-rose-700">{{ $t('Deleted') }}</span>
                        <span v-else-if="user.is_blocked" class="rounded-sm bg-amber-50 px-2 py-0.5 text-xs text-amber-700">{{ $t('Blocked') }}</span>
                        <span v-else class="rounded-sm bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ $t('Active') }}</span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <UsersTableActions
                            :user="user"
                            @edit="$emit('edit', user)"
                            @block="$emit('block', user)"
                            @unblock="$emit('unblock', user)"
                            @delete="$emit('delete', user)"
                            @restore="$emit('restore', user)"
                            @impersonate="$emit('impersonate', user)"
                        />
                    </td>
                </tr>
                <tr v-if="!users.length">
                    <td colspan="8" class="px-3 py-6 text-center text-ink-soft">{{ $t('No users found') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
