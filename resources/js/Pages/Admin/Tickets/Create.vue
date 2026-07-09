<script setup>
/**
 * Operator: open a ticket on a user's behalf (phase 4.2).
 *
 * Reuses the shared TicketForm for the common fields and adds the operator-only
 * extras in its slot: a user picker (searching the admin user endpoint with the
 * axios-free fetch idiom) and the triage labels. Submits to the admin store; the
 * controller starts the ticket `wait-customer` and notifies the user.
 */
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { AdminLayout, TicketForm } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    // Optional pre-picked customer (phase 3a): a "Create ticket" action on the
    // admin user list arrives with the user already selected.
    preselectedUser: { type: Object, default: null },
});

const form = useForm({
    user_id: props.preselectedUser?.id ?? null,
    title: '',
    message: '',
    priority: props.priorities[0] ?? 'standard',
    categories: [],
    labels: [],
});

const search = ref('');
const results = ref([]);
const selected = ref(props.preselectedUser);
const searching = ref(false);
const searched = ref(false);

async function runSearch() {
    if (search.value.trim() === '') {
        results.value = [];
        searched.value = false;

        return;
    }

    searching.value = true;

    try {
        const response = await fetch(`/admin/users/search?search=${encodeURIComponent(search.value)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await response.json();
        results.value = data.users?.data ?? data.users ?? [];
    } catch {
        results.value = [];
    } finally {
        searching.value = false;
        searched.value = true;
    }
}

function pick(user) {
    selected.value = user;
    form.user_id = user.id;
    results.value = [];
    search.value = '';
}

function isLabelChecked(slug) {
    return form.labels.includes(slug);
}

function toggleLabel(slug) {
    const set = new Set(form.labels);
    set.has(slug) ? set.delete(slug) : set.add(slug);
    form.labels = [...set];
}

function submit() {
    form.post('/admin/tickets');
}
</script>

<template>
    <AdminLayout :title="$t('Support')">
        <div class="mx-auto w-full max-w-2xl space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-ink">{{ $t('New ticket') }}</h1>
                <Link href="/admin/tickets" class="text-sm font-medium text-brand-600 hover:underline">
                    {{ $t('Back to tickets') }}
                </Link>
            </div>

            <form class="space-y-4 rounded-sm border border-border bg-surface p-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-ink" for="user-search">
                        {{ $t('Customer') }} <span class="text-danger">*</span>
                    </label>
                    <p v-if="selected" class="text-sm text-ink">
                        {{ selected.name }} ({{ selected.email }})
                        <button type="button" class="ml-2 text-xs text-brand-600 hover:underline" @click="selected = null; form.user_id = null">
                            {{ $t('Cancel') }}
                        </button>
                    </p>
                    <div v-else class="flex gap-2">
                        <input
                            id="user-search"
                            v-model="search"
                            type="search"
                            class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                            @keydown.enter.prevent="runSearch"
                        />
                        <button type="button" class="rounded-sm border border-border px-3 py-2 text-sm text-ink-soft hover:bg-surface-accent" @click="runSearch">
                            {{ $t('Search') }}
                        </button>
                    </div>
                    <ul v-if="results.length" class="mt-1 divide-y divide-border rounded-sm border border-border">
                        <li v-for="user in results" :key="user.id">
                            <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-accent" @click="pick(user)">
                                {{ user.name }} <span class="text-ink-soft">({{ user.email }})</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="searching" class="mt-1 text-sm text-ink-soft">{{ $t('Loading…') }}</p>
                    <p v-else-if="searched && !selected" class="mt-1 text-sm text-ink-soft">{{ $t('No users found') }}</p>
                    <p v-if="form.errors.user_id" class="text-xs text-danger">{{ form.errors.user_id }}</p>
                </div>

                <TicketForm :form="form" :categories="categories" :priorities="priorities">
                    <div v-if="labels.length" class="flex flex-col gap-1">
                        <span class="text-sm font-medium text-ink">{{ $t('Labels') }}</span>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="slug in labels"
                                :key="slug"
                                class="inline-flex cursor-pointer items-center gap-2 rounded-sm border border-border px-3 py-1.5 text-sm transition"
                                :class="isLabelChecked(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'text-ink-soft hover:bg-surface-accent'"
                            >
                                <input type="checkbox" class="sr-only" :checked="isLabelChecked(slug)" @change="toggleLabel(slug)" />
                                {{ $t(`tickets.label.${slug}`) }}
                            </label>
                        </div>
                    </div>
                </TicketForm>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        {{ $t('Send') }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
