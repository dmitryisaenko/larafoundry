<script setup>
/**
 * Language switcher.
 *
 * Lists the available locales the core shares (`available_locales` Inertia prop)
 * and POSTs the chosen one to the language-switch route. The server validates the
 * code against the whitelist and persists it (session + cookie, plus the DB
 * preference when signed in); SetLocale applies it on the next request, so the
 * switch takes effect after the visit reloads.
 *
 * Data comes from props the core shares via HandleInertiaRequests, so the
 * component works on any page without extra wiring. It degrades gracefully:
 * with one locale (or none) there is nothing to switch, so it renders nothing.
 */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    /** [{ code, native, flag }] — defaults to the shared `available_locales`. */
    locales: { type: Array, default: null },
    /** Active locale code — defaults to the shared `locale`. */
    current: { type: String, default: null },
});

const page = usePage();

const options = computed(() => props.locales ?? page.props.available_locales ?? []);
const currentCode = computed(() => props.current ?? page.props.locale ?? null);
const active = computed(
    () => options.value.find((l) => l.code === currentCode.value) ?? null,
);

const open = ref(false);

function switchTo(locale) {
    open.value = false;

    if (locale.code === currentCode.value) {
        return;
    }

    router.post(
        route('larafoundry.language.switch'),
        { locale: locale.code },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div v-if="options.length > 1" class="relative">
        <button
            type="button"
            class="flex items-center gap-2 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink transition hover:bg-surface-subtle"
            @click="open = !open"
        >
            <span v-if="active?.flag">{{ active.flag }}</span>
            <span class="font-medium">{{ active?.native ?? currentCode }}</span>
            <span class="text-ink-soft">▾</span>
        </button>

        <ul
            v-if="open"
            class="absolute z-10 mt-1 w-44 overflow-hidden rounded-sm border border-border bg-surface shadow-md"
        >
            <li v-for="locale in options" :key="locale.code">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink transition hover:bg-surface-subtle"
                    :class="locale.code === currentCode ? 'bg-brand-50 text-brand-700' : ''"
                    @click="switchTo(locale)"
                >
                    <span v-if="locale.flag">{{ locale.flag }}</span>
                    <span>{{ locale.native }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
