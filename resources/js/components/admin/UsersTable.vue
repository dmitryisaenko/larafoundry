<script setup>
/**
 * Super-admin user list table (phase 2.3, extended 3a; legacy-parity restyle
 * phase 7).
 *
 * Renders the rows shaped by AdminUserResource and emits row actions up to the
 * page (edit/block/unblock/delete/restore/impersonate/verify/logs/ticket), which
 * own the requests. No v-html anywhere.
 *
 * Layout mirrors the boot kohana console: dense rows, contact grouped into one
 * Email / Phone cell (with per-channel verify ticks and social links beneath),
 * registration + last-activity grouped into one cell (stale activity flagged in
 * a warning colour), the block/delete reason shown under the name, and the whole
 * row tinted amber (blocked) or rose (deleted). Status is the tint, not a column,
 * so the standalone Language / Auth / Status columns are gone.
 *
 * PII columns are opt-in (phase 3a): Phone, Sex, Age and Social render only when
 * the host switched the matching token on in `larafoundry.admin.user_columns`,
 * surfaced here as the `userColumns` prop — the resource does not even serialise
 * those fields unless opted in. Sex and Age are their own columns; Phone and
 * Social fold into the Email / Phone cell.
 *
 * HOST SEAM (phase 7): each row may carry `extra_columns` — display cells a host
 * appended by subclassing AdminUserResource (see its `extra()`). The header is
 * derived from the union of cell keys across rows, so a host gets its column with
 * zero fork. A cell is either text (`value` + optional `badge` pill) or a row of
 * small badges (`items`); a badge click leaves here as `extra-action` (row, column
 * key, badge key) and the PAGE owns the request — the table stores no badge state
 * and knows nothing about what the host is toggling.
 */
import { computed } from 'vue';
import UsersTableActions from './UsersTableActions.vue';
import SocialIcon from './SocialIcon.vue';
import CellIcon from './CellIcon.vue';
import { hasCellIcon } from './cellIcons.js';
import UserAvatar from '../media/UserAvatar.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    // Sanitised opt-in column tokens the backend switched on (phase 3a).
    userColumns: { type: Array, default: () => [] },
});

defineEmits([
    'edit',
    'block',
    'unblock',
    'delete',
    'restore',
    'impersonate',
    'verify-email',
    'unverify-email',
    'verify-phone',
    'unverify-phone',
    'logs',
    'ticket',
    // Host seam: a badge in a host extra column was clicked. Payload: the user
    // row, the column key, the badge key. Nothing is saved or re-rendered here.
    'extra-action',
]);

function has(token) {
    return props.userColumns.includes(token);
}

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

// Colour token -> pill classes, shared by host extra badges.
const badgeClass = {
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    rose: 'bg-rose-50 text-rose-700',
    slate: 'bg-slate-100 text-slate-700',
};

function pill(token) {
    return badgeClass[token] ?? badgeClass.slate;
}

// Host badge cells (`items`): tone -> text colour, same class families the rest
// of this file uses, so dark theme comes along for free. An unknown tone reads as
// `neutral` rather than dropping the badge — a stray token must not cost the
// operator a control.
const toneClass = {
    on: 'text-emerald-600 dark:text-emerald-400',
    off: 'text-rose-600 dark:text-rose-400',
    muted: 'text-ink-faint',
    neutral: 'text-ink-soft',
};

function tone(token) {
    return toneClass[token] ?? toneClass.neutral;
}

// A badge is inert only when the host says so explicitly.
function badgeClickable(item) {
    return item.clickable !== false;
}

// The caption is the fallback for an unknown icon name too, so a glyph the
// package does not know degrades to text instead of an empty badge.
function badgeText(item) {
    if (item.icon && !hasCellIcon(item.icon)) {
        return item.text ?? '';
    }
    return item.icon ? '' : (item.text ?? '');
}

// A cell is a badge set as soon as it CARRIES `items` — an empty (or malformed)
// list renders a dash, it does not fall through to `value`. That keeps the two
// forms from mixing: a host whose badges all collapsed for one user gets the
// dash, never a stale text value from the other form.
function isBadgeCell(user, key) {
    return cellFor(user, key).items !== undefined;
}

// Only real badge objects survive. A host that ships `items` as a string, or
// with a null hole in it, gets an empty cell instead of ghost buttons or a
// TypeError that would cost the operator the whole page — the same "a malformed
// cell must not break the row" rule already applied to `tone` and `icon`.
function badgeItems(user, key) {
    const items = cellFor(user, key).items;

    if (!Array.isArray(items)) {
        return [];
    }

    return items.filter((item) => item !== null && typeof item === 'object');
}

// Sex is stored canonically as a single character ('m'/'f'), the same value the
// self-profile writes — so the label maps the canon, with a raw fallback for any
// other stored value.
function sexLabel(value) {
    if (value === 'm') {
        return 'Male';
    }
    if (value === 'f') {
        return 'Female';
    }
    return value ?? '-';
}

// Row tint by account state (legacy parity): blocked = amber wash, deleted = rose
// wash. Opacity-based so it reads correctly in both light and dark themes.
function rowTint(user) {
    if (user.is_deleted) {
        return 'bg-rose-500/10';
    }
    if (user.is_blocked) {
        return 'bg-amber-500/10';
    }
    return '';
}

// Fixed columns for the empty-state colspan: ID, Name, Email/Phone,
// Registered/Activity, Comp./Empl., Country, Actions = 7. Phone/Social fold into
// the Email/Phone cell (no own column); only Sex and Age add columns.
const FIXED_COLUMNS = 7;
const OPTIONAL_TOKENS = ['sex', 'age'];
const optionalCount = computed(() => OPTIONAL_TOKENS.filter((t) => has(t)).length);
const colspan = computed(() => FIXED_COLUMNS + optionalCount.value + extraColumns.value.length);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">{{ $t('ID') }}</th>
                    <th class="px-3 py-2">{{ $t('Name') }}</th>
                    <th class="px-3 py-2">{{ $t('Email') }} / {{ $t('Phone') }}</th>
                    <th class="px-3 py-2">{{ $t('Registered') }} / {{ $t('Last activity') }}</th>
                    <th class="px-3 py-2">{{ $t('Comp. / Empl.') }}</th>
                    <th class="px-3 py-2">{{ $t('Country') }}</th>
                    <th v-if="has('sex')" class="px-3 py-2">{{ $t('Sex') }}</th>
                    <th v-if="has('age')" class="px-3 py-2">{{ $t('Age') }}</th>
                    <th v-for="col in extraColumns" :key="col.key" class="px-3 py-2">{{ $t(col.label) }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="user in users"
                    :key="user.id"
                    class="border-b border-border/60 align-top"
                    :class="rowTint(user)"
                >
                    <td class="px-3 py-2 text-ink-soft">{{ user.id }}</td>

                    <!-- Name: avatar + name, with the block/delete reason beneath. -->
                    <td class="px-3 py-2">
                        <div class="flex items-start gap-2">
                            <UserAvatar
                                :src="user.avatar_url"
                                :name="`${user.name} ${user.lastname || ''}`"
                                :size="32"
                            />
                            <div>
                                <div class="font-medium text-ink">{{ user.name }} {{ user.lastname }}</div>
                                <div v-if="user.is_admin" class="text-xs font-semibold text-brand-700">{{ $t('Super-admin') }}</div>
                                <div v-if="user.is_deleted" class="text-xs text-rose-700 dark:text-rose-300">
                                    {{ $t('Deleted') }}<template v-if="user.deleted_at_human"> · {{ user.deleted_at_human }}</template>
                                </div>
                                <div v-else-if="user.is_blocked" class="text-xs text-amber-700 dark:text-amber-300">
                                    {{ user.block_reason || $t('Blocked') }}<template v-if="user.blocked_at_human"> · {{ user.blocked_at_human }}</template>
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- Email / Phone (+ social), each contact channel with a verify tick. -->
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-1">
                            <span class="text-ink">{{ user.email }}</span>
                            <span v-if="user.email_verified" class="text-xs text-emerald-600" :title="$t('Verified')">✓</span>
                            <span v-else class="text-xs text-rose-500" :title="$t('Unverified')">✗</span>
                        </div>
                        <div v-if="has('phone')" class="flex items-center gap-1 text-ink-soft">
                            <template v-if="user.phone">
                                <span>{{ user.phone }}</span>
                                <span v-if="user.phone_verified" class="text-xs text-emerald-600" :title="$t('Verified')">✓</span>
                                <span v-else class="text-xs text-rose-500" :title="$t('Unverified')">✗</span>
                            </template>
                            <span v-else>-</span>
                        </div>
                        <div v-if="has('social') && (user.social_links || []).length" class="mt-1 flex items-center gap-2">
                            <a
                                v-for="(link, i) in user.social_links"
                                :key="i"
                                :href="link.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-ink-soft transition hover:text-brand-700"
                                :title="link.platform"
                            >
                                <SocialIcon :platform="link.platform" />
                            </a>
                        </div>
                    </td>

                    <!-- Registered / last activity (stale activity flagged). -->
                    <td class="px-3 py-2 text-ink-soft">
                        <div>{{ user.registered_date || '-' }}</div>
                        <div :class="user.last_activity_stale ? 'text-amber-600 dark:text-amber-400' : 'text-ink-faint'">
                            {{ user.last_activity_human || '-' }}
                        </div>
                    </td>

                    <td class="px-3 py-2 text-ink-soft">
                        {{ user.owned_companies_count ?? 0 }} / {{ user.employee_companies_count ?? 0 }}
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ user.country || '-' }}</td>
                    <td v-if="has('sex')" class="px-3 py-2 text-ink-soft">
                        {{ user.sex ? $t(sexLabel(user.sex)) : '-' }}
                    </td>
                    <td v-if="has('age')" class="px-3 py-2 text-ink-soft">{{ user.age ?? '-' }}</td>

                    <td
                        v-for="col in extraColumns"
                        :key="col.key"
                        class="px-3 py-2 text-ink-soft"
                        data-extra-cell
                    >
                        <!-- Badge set: one clickable control per switch, the click
                             handed to the page (this table saves nothing). -->
                        <div v-if="isBadgeCell(user, col.key)" class="flex items-center gap-1.5">
                            <component
                                :is="badgeClickable(item) ? 'button' : 'span'"
                                v-for="(item, i) in badgeItems(user, col.key)"
                                :key="item.key ?? i"
                                :type="badgeClickable(item) ? 'button' : null"
                                :title="item.title || null"
                                :aria-label="item.title || item.text || item.key"
                                class="inline-flex min-w-6 items-center justify-center rounded-sm px-1 py-0.5 font-mono text-xs leading-none transition"
                                :class="[
                                    tone(item.tone),
                                    badgeClickable(item)
                                        ? 'cursor-pointer hover:bg-surface-muted hover:ring-1 hover:ring-border'
                                        : '',
                                ]"
                                @click="badgeClickable(item) ? $emit('extra-action', user, col.key, item.key) : null"
                            >
                                <CellIcon v-if="item.icon" :name="item.icon" />
                                <span v-if="badgeText(item)">{{ badgeText(item) }}</span>
                            </component>
                            <span v-if="!badgeItems(user, col.key).length">-</span>
                        </div>
                        <span
                            v-else-if="cellFor(user, col.key).badge"
                            class="rounded-sm px-2 py-0.5 text-xs"
                            :class="pill(cellFor(user, col.key).badge)"
                        >{{ cellFor(user, col.key).value }}</span>
                        <span v-else>{{ cellFor(user, col.key).value ?? '-' }}</span>
                    </td>

                    <td class="px-3 py-2 text-right">
                        <UsersTableActions
                            :user="user"
                            :user-columns="userColumns"
                            @edit="$emit('edit', user)"
                            @block="$emit('block', user)"
                            @unblock="$emit('unblock', user)"
                            @delete="$emit('delete', user)"
                            @restore="$emit('restore', user)"
                            @impersonate="$emit('impersonate', user)"
                            @verify-email="$emit('verify-email', user)"
                            @unverify-email="$emit('unverify-email', user)"
                            @verify-phone="$emit('verify-phone', user)"
                            @unverify-phone="$emit('unverify-phone', user)"
                            @logs="$emit('logs', user)"
                            @ticket="$emit('ticket', user)"
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
