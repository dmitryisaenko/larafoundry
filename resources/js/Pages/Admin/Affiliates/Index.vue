<script setup>
/**
 * Super-admin affiliates console (Ф4.4).
 *
 * A filtered, paginated list of partners with their referral and commission counts. A
 * super-admin can edit a partner's override rate / status inline, and — in the `admin`
 * eligibility mode — enrol a new partner for a chosen user. Gated server-side by
 * `larafoundry.admin`; this page only wires filters and the mutating actions.
 */
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    affiliates: { type: Object, default: () => ({ data: [] }) },
    pagination: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    eligibility: { type: String, default: 'auto' },
});

const form = reactive({
    status: props.filters.status ?? '',
    code: props.filters.code ?? '',
    email: props.filters.email ?? '',
});

let debounce = null;
watch(form, () => {
    clearTimeout(debounce);
    debounce = setTimeout(go, 350);
});

function cleaned() {
    const params = {};
    for (const [key, value] of Object.entries(form)) {
        if (value !== '' && value !== null) {
            params[key] = value;
        }
    }
    return params;
}

function go() {
    router.get('/admin/affiliates', cleaned(), { preserveState: true, replace: true });
}

// Inline edit of one partner's override rate + status.
const editingId = ref(null);
const editForm = reactive({ commission_rate_bp: '', status: 'active' });

function startEdit(affiliate) {
    editingId.value = affiliate.id;
    editForm.commission_rate_bp = affiliate.commission_rate_bp ?? '';
    editForm.status = affiliate.status;
}

function cancelEdit() {
    editingId.value = null;
}

function saveEdit(affiliate) {
    router.put(`/admin/affiliates/${affiliate.id}`, {
        commission_rate_bp: editForm.commission_rate_bp === '' ? null : Number(editForm.commission_rate_bp),
        status: editForm.status,
    }, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
}

// Enrol form (admin eligibility) with user autocomplete.
const showEnrol = ref(false);
const enrol = reactive({ user_id: null, user_label: '', commission_rate_bp: '', status: 'active' });
const userQuery = ref('');
const userResults = ref([]);
let searchDebounce = null;

watch(userQuery, (value) => {
    clearTimeout(searchDebounce);
    if (!value || value.length < 2) {
        userResults.value = [];
        return;
    }
    searchDebounce = setTimeout(async () => {
        const res = await fetch(`/admin/affiliates/search-users?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
        });
        userResults.value = res.ok ? (await res.json()).data : [];
    }, 300);
});

function pickUser(user) {
    enrol.user_id = user.id;
    enrol.user_label = `${user.fullname} <${user.email}>`;
    userResults.value = [];
    userQuery.value = '';
}

function submitEnrol() {
    router.post('/admin/affiliates', {
        user_id: enrol.user_id,
        commission_rate_bp: enrol.commission_rate_bp === '' ? null : Number(enrol.commission_rate_bp),
        status: enrol.status,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showEnrol.value = false;
            enrol.user_id = null;
            enrol.user_label = '';
            enrol.commission_rate_bp = '';
        },
    });
}

function statusLabel(status) {
    return { active: 'Active', disabled: 'Disabled', pending: 'Pending' }[status] ?? status;
}
</script>

<template>
    <AdminLayout :title="$t('Affiliates')">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">{{ $t('Affiliates') }}</h1>
                <button v-if="eligibility === 'admin'" class="btn btn-primary" @click="showEnrol = !showEnrol">
                    {{ $t('Enrol affiliate') }}
                </button>
            </div>

            <div v-if="showEnrol" class="rounded border p-4 space-y-3">
                <div class="relative">
                    <label class="block text-sm">{{ $t('Search users by name or email') }}</label>
                    <input v-model="userQuery" type="text" class="input w-full" />
                    <div v-if="enrol.user_label" class="text-sm mt-1">{{ enrol.user_label }}</div>
                    <ul v-if="userResults.length" class="absolute z-10 bg-white border w-full">
                        <li v-for="u in userResults" :key="u.id" class="px-2 py-1 cursor-pointer hover:bg-gray-100" @click="pickUser(u)">
                            {{ u.fullname }} &lt;{{ u.email }}&gt;
                        </li>
                    </ul>
                </div>
                <div class="flex gap-2">
                    <input v-model="enrol.commission_rate_bp" type="number" min="0" class="input" :placeholder="$t('Override rate, basis points')" />
                    <select v-model="enrol.status" class="input">
                        <option value="active">{{ $t('Active') }}</option>
                        <option value="pending">{{ $t('Pending') }}</option>
                        <option value="disabled">{{ $t('Disabled') }}</option>
                    </select>
                    <button class="btn btn-primary" :disabled="!enrol.user_id" @click="submitEnrol">{{ $t('Create') }}</button>
                    <button class="btn" @click="showEnrol = false">{{ $t('Cancel') }}</button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <select v-model="form.status" class="input">
                    <option value="">{{ $t('Any status') }}</option>
                    <option value="active">{{ $t('Active') }}</option>
                    <option value="pending">{{ $t('Pending') }}</option>
                    <option value="disabled">{{ $t('Disabled') }}</option>
                </select>
                <input v-model="form.code" type="text" class="input" :placeholder="$t('Search code')" />
                <input v-model="form.email" type="text" class="input" :placeholder="$t('Partner email')" />
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th>{{ $t('Code') }}</th>
                        <th>{{ $t('Partner') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th>{{ $t('Rate') }}</th>
                        <th>{{ $t('Referrals') }}</th>
                        <th>{{ $t('Commissions') }}</th>
                        <th>{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in affiliates.data" :key="a.id" class="border-t">
                        <td class="font-mono">{{ a.code }}</td>
                        <td>{{ a.user_fullname }} <span class="text-gray-500">{{ a.user_email }}</span></td>
                        <td>
                            <template v-if="editingId === a.id">
                                <select v-model="editForm.status" class="input">
                                    <option value="active">{{ $t('Active') }}</option>
                                    <option value="pending">{{ $t('Pending') }}</option>
                                    <option value="disabled">{{ $t('Disabled') }}</option>
                                </select>
                            </template>
                            <template v-else>{{ $t(statusLabel(a.status)) }}</template>
                        </td>
                        <td>
                            <template v-if="editingId === a.id">
                                <input v-model="editForm.commission_rate_bp" type="number" min="0" class="input w-24" />
                            </template>
                            <template v-else>{{ a.commission_rate_bp ?? $t('Default rate') }}</template>
                        </td>
                        <td>{{ a.referrals_count }}</td>
                        <td>{{ a.pending_count }} / {{ a.approved_count }} / {{ a.paid_count }}</td>
                        <td>
                            <template v-if="editingId === a.id">
                                <button class="btn btn-primary" @click="saveEdit(a)">{{ $t('Save changes') }}</button>
                                <button class="btn" @click="cancelEdit">{{ $t('Cancel') }}</button>
                            </template>
                            <template v-else>
                                <button class="btn" @click="startEdit(a)">{{ $t('Edit') }}</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!affiliates.data.length">
                        <td colspan="7" class="py-4 text-center text-gray-500">{{ $t('No affiliates found.') }}</td>
                    </tr>
                </tbody>
            </table>

            <PagePaginator :pagination="pagination" />
        </div>
    </AdminLayout>
</template>

<script>
function statusLabel(status) {
    return { active: 'Active', disabled: 'Disabled', pending: 'Pending' }[status] ?? status;
}
</script>
