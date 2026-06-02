<script setup>
/**
 * Roles of the active company: the cloned template roles plus any custom ones.
 *
 * Data is scoped to the active company server-side; create/edit/delete go to the
 * authorization.roles routes, which re-check the `roles.*` gates and that the role
 * belongs to the active company. The `can.create` flag and per-row editable/
 * deletable flags come from the controller, so the UI mirrors the server's rules.
 */
import { Link, router } from '@inertiajs/vue3';
import { AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ create: false }) },
});

function destroy(role) {
    if (confirm(`${role.name}?`)) {
        router.delete(`/roles/${role.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-ink">{{ $t('Roles') }}</h1>
                <Link
                    v-if="can.create"
                    href="/roles/create"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-sm text-white transition hover:bg-brand-600"
                >
                    {{ $t('New role') }}
                </Link>
            </div>

            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-ink-soft">
                        <th class="py-2">{{ $t('Name') }}</th>
                        <th class="py-2">{{ $t('Permissions') }}</th>
                        <th class="py-2">{{ $t('Members') }}</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles" :key="role.id" class="border-b border-border">
                        <td class="py-2 text-ink">
                            {{ role.name }}
                            <span v-if="!role.is_custom" class="ml-1 text-xs text-ink-soft">({{ $t('default') }})</span>
                        </td>
                        <td class="py-2 text-ink-soft">{{ role.permissions.length }}</td>
                        <td class="py-2 text-ink-soft">{{ role.users_count }}</td>
                        <td class="py-2 text-right">
                            <Link
                                v-if="role.editable"
                                :href="`/roles/${role.id}/edit`"
                                class="text-xs text-brand-600 hover:underline"
                            >
                                {{ $t('Edit') }}
                            </Link>
                            <button
                                v-if="role.deletable"
                                type="button"
                                class="ml-3 text-xs text-danger hover:underline"
                                @click="destroy(role)"
                            >
                                {{ $t('Delete') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppBaseLayout>
</template>
