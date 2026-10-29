<script setup>
/**
 * Create a promo code (sub-phase B7b). Posts to the B5-backed store route; the server
 * re-validates and authorizes. The personal-code user lookup hits the ADD-ON's own gated
 * admin route (`/admin/promo-codes/search-users`), never a host route.
 */
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { AdminLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    discount_types: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
});

const form = useForm({
    code: '',
    kind: 'discount',
    discount_type: props.discount_types[0]?.value ?? 'percentage',
    discount_value: 0,
    description: '',
    stripe_coupon_id: '',
    paddle_discount_id: '',
    max_uses: null,
    single_use_per_user: true,
    is_active: true,
    expires_at: '',
    user_id: null,
});

const userQuery = ref('');
const userResults = ref([]);
const selectedUser = ref(null);
let userDebounce = null;

function onCodeInput(event) {
    form.code = event.target.value.toUpperCase();
}

function searchUsers() {
    clearTimeout(userDebounce);
    userDebounce = setTimeout(async () => {
        if (userQuery.value.trim().length < 2) {
            userResults.value = [];
            return;
        }
        try {
            const { data } = await axios.get('/admin/promo-codes/search-users', { params: { search: userQuery.value } });
            userResults.value = Array.isArray(data) ? data : [];
        } catch (e) {
            userResults.value = [];
        }
    }, 300);
}

function pickUser(user) {
    selectedUser.value = user;
    form.user_id = user.id;
    userResults.value = [];
    userQuery.value = '';
}

function clearUser() {
    selectedUser.value = null;
    form.user_id = null;
}

function submit() {
    form.post(route('admin.promo-codes.store'));
}
</script>

<template>
    <AdminLayout :title="$t('New promo code')">
        <div class="mx-auto w-full max-w-2xl">
            <Link href="/admin/promo-codes" class="mb-4 inline-block text-sm text-brand-600 hover:text-brand-700">&larr; {{ $t('Back to promo codes') }}</Link>

            <form class="space-y-5 rounded-sm border border-border bg-surface p-6" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Code') }}</label>
                        <input :value="form.code" type="text" maxlength="50" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm uppercase text-ink" @input="onCodeInput">
                        <p v-if="form.errors.code" class="mt-1 text-xs text-danger">{{ form.errors.code }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Kind') }}</label>
                        <select v-model="form.kind" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="k in kinds" :key="k.value" :value="k.value">{{ $t(k.name) }}</option>
                        </select>
                        <p v-if="form.errors.kind" class="mt-1 text-xs text-danger">{{ form.errors.kind }}</p>
                    </div>

                    <template v-if="form.kind === 'discount'">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Discount type') }}</label>
                            <select v-model="form.discount_type" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                                <option v-for="d in discount_types" :key="d.value" :value="d.value">{{ $t(d.name) }}</option>
                            </select>
                            <p v-if="form.errors.discount_type" class="mt-1 text-xs text-danger">{{ form.errors.discount_type }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">
                                {{ $t('Discount value') }}
                                <span class="text-ink-soft">({{ form.discount_type === 'percentage' ? $t('percent, max 100') : $t('minor units') }})</span>
                            </label>
                            <input v-model.number="form.discount_value" type="number" min="0" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                            <p v-if="form.errors.discount_value" class="mt-1 text-xs text-danger">{{ form.errors.discount_value }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Stripe coupon id') }} <span class="text-ink-soft">({{ $t('optional') }})</span></label>
                            <input v-model="form.stripe_coupon_id" type="text" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Paddle discount id') }} <span class="text-ink-soft">({{ $t('optional') }})</span></label>
                            <input v-model="form.paddle_discount_id" type="text" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        </div>
                    </template>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Max uses') }} <span class="text-ink-soft">({{ $t('blank = unlimited') }})</span></label>
                        <input v-model.number="form.max_uses" type="number" min="1" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <p v-if="form.errors.max_uses" class="mt-1 text-xs text-danger">{{ form.errors.max_uses }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Expires at') }} <span class="text-ink-soft">({{ $t('blank = never') }})</span></label>
                        <input v-model="form.expires_at" type="datetime-local" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <p v-if="form.errors.expires_at" class="mt-1 text-xs text-danger">{{ form.errors.expires_at }}</p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Description') }} <span class="text-ink-soft">({{ $t('optional') }})</span></label>
                    <textarea v-model="form.description" rows="2" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"></textarea>
                    <p v-if="form.errors.description" class="mt-1 text-xs text-danger">{{ form.errors.description }}</p>
                </div>

                <!-- Personal-code owner -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Personal code for user') }} <span class="text-ink-soft">({{ $t('optional') }})</span></label>
                    <div v-if="selectedUser" class="flex items-center gap-2 rounded-sm bg-surface-subtle px-3 py-2 text-sm">
                        <span class="text-ink">{{ selectedUser.fullname || selectedUser.email }}</span>
                        <span class="text-ink-faint">{{ selectedUser.email }}</span>
                        <button type="button" class="ml-auto text-xs text-danger" @click="clearUser">{{ $t('Clear') }}</button>
                    </div>
                    <div v-else class="relative">
                        <input v-model="userQuery" type="search" :placeholder="$t('Search users by name or email')" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink" @input="searchUsers">
                        <ul v-if="userResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-sm border border-border bg-surface shadow">
                            <li v-for="u in userResults" :key="u.id" class="cursor-pointer px-3 py-2 text-sm text-ink hover:bg-surface-subtle" @click="pickUser(u)">
                                {{ u.fullname || u.email }} <span class="text-ink-faint">{{ u.email }}</span>
                            </li>
                        </ul>
                    </div>
                    <p v-if="form.errors.user_id" class="mt-1 text-xs text-danger">{{ form.errors.user_id }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.single_use_per_user" type="checkbox"> {{ $t('Single use per user') }}</label>
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.is_active" type="checkbox"> {{ $t('Active') }}</label>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-sm bg-brand-500 px-4 py-2 text-sm text-white transition hover:bg-brand-600 disabled:opacity-50">
                        {{ form.processing ? $t('Processing…') : $t('Create promo code') }}
                    </button>
                    <Link href="/admin/promo-codes" class="rounded-sm border border-border px-4 py-2 text-sm text-ink-soft transition hover:bg-surface-subtle">{{ $t('Cancel') }}</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
