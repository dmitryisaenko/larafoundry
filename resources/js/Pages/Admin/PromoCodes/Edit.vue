<script setup>
/**
 * Edit a promo code (sub-phase B7b). Code, kind, discount type and the personal-user
 * binding are immutable here (shown read-only); the editable fields submit a partial
 * update to the B5-backed route. Submitting `discount_value` WITHOUT `discount_type`
 * exercises the UpdatePromoCodeRequest's persisted-type fallback for the percentage cap.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { AdminLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    promo_code: { type: Object, required: true },
    discount_types: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
});

const form = useForm({
    description: props.promo_code.description ?? '',
    discount_value: props.promo_code.discount_value,
    max_uses: props.promo_code.max_uses,
    single_use_per_user: props.promo_code.single_use_per_user,
    is_active: props.promo_code.is_active,
    expires_at: props.promo_code.expires_at ?? '',
});

function label(value) {
    if (!value) {
        return '—';
    }
    const text = String(value);
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function submit() {
    form.put(route('admin.promo-codes.update', props.promo_code.id));
}
</script>

<template>
    <AdminLayout :title="$t('Edit promo code')">
        <div class="mx-auto w-full max-w-2xl">
            <Link href="/admin/promo-codes" class="mb-4 inline-block text-sm text-brand-600 hover:text-brand-700">&larr; {{ $t('Back to promo codes') }}</Link>

            <!-- Immutable summary -->
            <div class="mb-4 grid grid-cols-2 gap-3 rounded-sm border border-border bg-surface-subtle p-4 text-sm sm:grid-cols-4">
                <div><span class="block text-xs uppercase text-ink-faint">{{ $t('Code') }}</span><span class="font-medium text-ink">{{ promo_code.code }}</span></div>
                <div><span class="block text-xs uppercase text-ink-faint">{{ $t('Kind') }}</span><span class="text-ink">{{ promo_code.kind === 'free_month' ? $t('Free month') : $t('Discount') }}</span></div>
                <div><span class="block text-xs uppercase text-ink-faint">{{ $t('Discount type') }}</span><span class="text-ink">{{ $t(label(promo_code.discount_type)) }}</span></div>
                <div><span class="block text-xs uppercase text-ink-faint">{{ $t('Used') }}</span><span class="text-ink">{{ promo_code.used_count }}</span></div>
                <div v-if="promo_code.user_email" class="col-span-2 sm:col-span-4">
                    <span class="block text-xs uppercase text-ink-faint">{{ $t('Personal code for user') }}</span>
                    <span class="text-ink">{{ promo_code.user_fullname || promo_code.user_email }} <span class="text-ink-faint">{{ promo_code.user_email }}</span></span>
                </div>
            </div>

            <form class="space-y-5 rounded-sm border border-border bg-surface p-6" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div v-if="promo_code.kind === 'discount'">
                        <label class="mb-1 block text-sm font-medium text-ink">
                            {{ $t('Discount value') }}
                            <span class="text-ink-soft">({{ promo_code.discount_type === 'percentage' ? $t('percent, max 100') : $t('minor units') }})</span>
                        </label>
                        <input v-model.number="form.discount_value" type="number" min="0" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <p v-if="form.errors.discount_value" class="mt-1 text-xs text-danger">{{ form.errors.discount_value }}</p>
                    </div>

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

                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.single_use_per_user" type="checkbox"> {{ $t('Single use per user') }}</label>
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.is_active" type="checkbox"> {{ $t('Active') }}</label>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-sm bg-brand-500 px-4 py-2 text-sm text-white transition hover:bg-brand-600 disabled:opacity-50">
                        {{ form.processing ? $t('Processing…') : $t('Save changes') }}
                    </button>
                    <Link href="/admin/promo-codes" class="rounded-sm border border-border px-4 py-2 text-sm text-ink-soft transition hover:bg-surface-subtle">{{ $t('Cancel') }}</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
