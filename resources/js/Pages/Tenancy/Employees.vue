<script setup>
/**
 * Employees of the active company: members, pending invitations, and an invite
 * form (owners only).
 *
 * All data comes from the controller (scoped to the active company server-side);
 * actions POST/DELETE to the tenancy routes, which re-check ownership and that
 * each invitation belongs to the active company. Role management is absent here
 * — RBAC arrives in phase 1.3.
 */
import { useForm, router } from '@inertiajs/vue3';
import { InputField, AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
    // The active company's assignable roles ({ id, name }) for the optional
    // role-on-invite select. '' = "Specify later" (no role), the default.
    roles: { type: Array, default: () => [] },
    is_owner: { type: Boolean, default: false },
});

const inviteForm = useForm({ email: '', role_id: '' });

function invite() {
    inviteForm
        .transform((data) => ({ email: data.email, role_id: data.role_id || null }))
        .post('/employees/invite', {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset('email', 'role_id'),
        });
}

function resend(id) {
    router.post(`/employees/invitations/${id}/resend`, {}, { preserveScroll: true });
}

function revoke(id) {
    router.delete(`/employees/invitations/${id}`, { preserveScroll: true });
}

function remove(userId) {
    router.delete('/employees', { data: { user_id: userId }, preserveScroll: true });
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-3xl space-y-8 p-4">
            <section>
                <h1 class="mb-4 text-xl font-semibold text-ink">{{ $t('Employees') }}</h1>

                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-ink-soft">
                            <th class="py-2">{{ $t('Name') }}</th>
                            <th class="py-2">{{ $t('Email') }}</th>
                            <th class="py-2">{{ $t('Role') }}</th>
                            <th v-if="is_owner" class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="employee in employees" :key="employee.id" class="border-b border-border">
                            <td class="py-2 text-ink">{{ employee.name }}</td>
                            <td class="py-2 text-ink-soft">{{ employee.email }}</td>
                            <td class="py-2 text-ink-soft">
                                {{ employee.is_owner ? $t('Owner') : $t('Member') }}
                            </td>
                            <td v-if="is_owner" class="py-2 text-right">
                                <button
                                    v-if="!employee.is_owner"
                                    type="button"
                                    class="text-xs text-danger hover:underline"
                                    @click="remove(employee.id)"
                                >
                                    {{ $t('Remove') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="is_owner">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ $t('Invite an employee') }}</h2>
                <form class="flex items-end gap-2" @submit.prevent="invite">
                    <InputField
                        v-model="inviteForm.email"
                        :title="$t('Email')"
                        name="email"
                        type="email"
                        placeholder="teammate@example.com"
                        :required="true"
                        :errors="inviteForm.errors"
                        class="flex-1"
                    />
                    <label class="flex flex-col gap-1 text-sm font-medium text-ink">
                        <span>{{ $t('Role') }}</span>
                        <select
                            v-model="inviteForm.role_id"
                            name="role_id"
                            class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"
                        >
                            <option value="">{{ $t('Specify later') }}</option>
                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                    </label>
                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                    >
                        {{ $t('Send invite') }}
                    </button>
                </form>
            </section>

            <section v-if="is_owner && invitations.length">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ $t('Pending invitations') }}</h2>
                <ul class="space-y-2">
                    <li
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="flex items-center justify-between rounded-sm border border-border px-3 py-2 text-sm"
                    >
                        <span class="text-ink">{{ invitation.email }}</span>
                        <span class="flex gap-3">
                            <button type="button" class="text-brand-600 hover:underline" @click="resend(invitation.id)">
                                {{ $t('Resend') }}
                            </button>
                            <button type="button" class="text-danger hover:underline" @click="revoke(invitation.id)">
                                {{ $t('Revoke') }}
                            </button>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </AppBaseLayout>
</template>
