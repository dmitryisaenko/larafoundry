<script setup>
/**
 * Super-admin user list table (phase 2.3).
 *
 * Renders the rows shaped by AdminUserResource and emits row actions up to the
 * page (edit/block/unblock/delete/restore/impersonate), which own the requests.
 * No social links are shown — the resource omits them (finding #6, PII). No
 * v-html anywhere.
 *
 * HOST SEAM (phase 7): each row may carry `extra_columns` — display cells a host
 * appended by subclassing AdminUserResource (see its `extra()`). The header is
 * derived from the first row's cells, so a host gets its column with zero fork.
 */
import { computed } from 'vue';
import UsersTableActions from './UsersTableActions.vue';
import UserAvatar from '../media/UserAvatar.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
});

defineEmits(['edit', 'block', 'unblock', 'delete', 'restore', 'impersonate']);

// Host-added columns: the header is the UNION of cell keys across all rows, in
// first-seen order — not just row 0. A host `extra()` that emits a cell only for
// some users (e.g. a "demo" flag) then still gets a header, and the body below
// renders one <td> per header column (looked up by key) so cells never shift
// under the wrong header when a row omits one.
const extraColumns = computed(() => {
    const seen = new Map();
    for (const user of props.users) {
        for (const cell of user.extra_columns ?? []) {
            if (!seen.has(cell.key)) {
                seen.set(cell.key, { key: cell.key, label: cell.label });
            }
        }
    }
    return [...seen.values()];
});

// Look up a row's cell for a given host column key ({} when the row omits it),
// so the body stays aligned with the union header.
function cellFor(user, key) {
    return (user.extra_columns ?? []).find((c) => c.key === key) ?? {};
}

// Colour token -> pill classes, shared by the auth badge and host extra badges.
const badgeClass = {
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    rose: 'bg-rose-50 text-rose-700',
    slate: 'bg-slate-100 text-slate-700',
};

function pill(token) {
    return badgeClass[token] ?? badgeClass.slate;
}

// Total column count for the empty-state row: the fixed core columns + extras.
const CORE_COLUMNS = 10;
const colspan = computed(() => CORE_COLUMNS + extraColumns.value.length);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">{{ $t('Name') }}</th>
                    <th class="px-3 py-2">{{ $t('Email') }}</th>
                    <th class="px-3 py-2">{{ $t('Country') }}</th>
                    <th class="px-3 py-2">{{ $t('Language') }}</th>
                    <th class="px-3 py-2">{{ $t('Auth') }}</th>
                    <th class="px-3 py-2">{{ $t('Companies') }}</th>
                    <th class="px-3 py-2">{{ $t('Registered') }}</th>
                    <th class="px-3 py-2">{{ $t('Last activity') }}</th>
                    <th class="px-3 py-2">{{ $t('Status') }}</th>
                    <th v-for="col in extraColumns" :key="col.key" class="px-3 py-2">{{ $t(col.label) }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="user in users" :key="user.id" class="border-b border-border/60 align-middle">
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-2">
                            <UserAvatar
                                :src="user.avatar_url"
                                :name="`${user.name} ${user.lastname || ''}`"
                                :size="32"
                            />
                            <div>
                                <div class="font-medium text-ink">{{ user.name }} {{ user.lastname }}</div>
                                <div v-if="user.is_admin" class="text-xs font-semibold text-brand-700">{{ $t('Super-admin') }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <span>{{ user.email }}</span>
                        <span v-if="user.email_verified" class="ml-1 text-xs text-emerald-600" :title="$t('Verified')">✓</span>
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.country || '—' }}</td>
                    <td class="px-3 py-2 uppercase text-ink-soft">{{ user.locale || '—' }}</td>
                    <td class="px-3 py-2">
                        <span
                            v-if="user.auth_type === 'oauth'"
                            class="rounded-sm px-2 py-0.5 text-xs capitalize"
                            :class="pill('slate')"
                        >{{ $t('OAuth') }}<template v-if="user.auth_provider"> · {{ user.auth_provider }}</template></span>
                        <span v-else class="text-xs text-ink-soft">{{ $t('Password') }}</span>
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.companies_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.registered_date || '—' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.last_activity_human || '—' }}</td>
                    <td class="px-3 py-2">
                        <span v-if="user.is_deleted" class="rounded-sm bg-rose-50 px-2 py-0.5 text-xs text-rose-700">{{ $t('Deleted') }}</span>
                        <span v-else-if="user.is_blocked" class="rounded-sm bg-amber-50 px-2 py-0.5 text-xs text-amber-700">{{ $t('Blocked') }}</span>
                        <span v-else class="rounded-sm bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ $t('Active') }}</span>
                    </td>
                    <td v-for="col in extraColumns" :key="col.key" class="px-3 py-2 text-ink-soft">
                        <span
                            v-if="cellFor(user, col.key).badge"
                            class="rounded-sm px-2 py-0.5 text-xs"
                            :class="pill(cellFor(user, col.key).badge)"
                        >{{ cellFor(user, col.key).value }}</span>
                        <span v-else>{{ cellFor(user, col.key).value ?? '—' }}</span>
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
                    <td :colspan="colspan" class="px-3 py-6 text-center text-ink-soft">{{ $t('No users found') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
