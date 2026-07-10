<script setup>
/**
 * Repeatable social-links editor (phase 3b).
 *
 * v-model is an array of `{ platform, url }`. Each row is a platform select
 * (options come from the `platforms` prop — the same whitelist the server
 * validates against, so the two never drift) plus a URL input and a remove
 * button; an "Add link" button appends an empty row. No v-html anywhere — the
 * URL is only ever an input value here, and it is scheme-locked server-side
 * before it is ever rendered as an href.
 */
import { useT } from '../../composables/useT.js';

const t = useT();
const model = defineModel({ type: Array, default: () => [] });

const props = defineProps({
    // The recognised platform slugs, passed from the controller (config-driven).
    platforms: { type: Array, default: () => [] },
    // Field-level validation errors keyed as `social_links.0.url`, etc.
    errors: { type: Object, default: () => ({}) },
});

// Brand-cased i18n keys for the known slugs (the dictionary carries 'GitHub',
// 'LinkedIn', 'YouTube' — a naive capitalise would miss those). A host-added
// slug the map doesn't know falls back to a simple capitalise.
const PLATFORM_LABELS = {
    website: 'Website',
    twitter: 'Twitter',
    linkedin: 'LinkedIn',
    github: 'GitHub',
    facebook: 'Facebook',
    instagram: 'Instagram',
    telegram: 'Telegram',
    youtube: 'YouTube',
};

// Human label for a platform slug via i18n; falls back to a capitalised slug
// when a host adds one the dictionary doesn't know.
function platformLabel(slug) {
    return t(PLATFORM_LABELS[slug] ?? (slug.charAt(0).toUpperCase() + slug.slice(1)));
}

function rows() {
    return Array.isArray(model.value) ? model.value : [];
}

function addLink() {
    model.value = [...rows(), { platform: props.platforms[0] ?? '', url: '' }];
}

function removeLink(index) {
    model.value = rows().filter((_, i) => i !== index);
}

function updatePlatform(index, value) {
    model.value = rows().map((link, i) => (i === index ? { ...link, platform: value } : link));
}

function updateUrl(index, value) {
    model.value = rows().map((link, i) => (i === index ? { ...link, url: value } : link));
}

function errorFor(index, key) {
    return props.errors[`social_links.${index}.${key}`];
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium text-ink">{{ $t('Social links') }}</label>

        <div
            v-for="(link, index) in rows()"
            :key="index"
            class="flex flex-wrap items-start gap-2"
        >
            <select
                :value="link.platform"
                class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"
                @change="updatePlatform(index, $event.target.value)"
            >
                <option v-for="slug in platforms" :key="slug" :value="slug">
                    {{ platformLabel(slug) }}
                </option>
            </select>

            <div class="flex flex-1 flex-col gap-1">
                <input
                    :value="link.url"
                    type="url"
                    inputmode="url"
                    placeholder="https://"
                    class="w-full rounded-sm border bg-surface px-3 py-2 text-sm text-ink"
                    :class="errorFor(index, 'url') ? 'border-danger' : 'border-border'"
                    @input="updateUrl(index, $event.target.value)"
                >
                <p v-if="errorFor(index, 'url')" class="text-xs text-danger">{{ errorFor(index, 'url') }}</p>
                <p v-if="errorFor(index, 'platform')" class="text-xs text-danger">{{ errorFor(index, 'platform') }}</p>
            </div>

            <button
                type="button"
                class="rounded-sm border border-border px-3 py-2 text-sm text-ink-soft transition hover:bg-surface-subtle"
                @click="removeLink(index)"
            >
                {{ $t('Remove') }}
            </button>
        </div>

        <div>
            <button
                type="button"
                class="rounded-sm border border-border px-3 py-2 text-sm font-medium text-ink transition hover:bg-surface-subtle"
                @click="addLink"
            >
                {{ $t('Add link') }}
            </button>
        </div>
    </div>
</template>
