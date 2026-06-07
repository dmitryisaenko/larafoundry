<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Support;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Email\Support\HtmlSanitizer;
use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The legal-page store: a config-registry of editable pages with an optional
 * per-page database override (phase 5.3).
 *
 * Mirrors {@see EmailTemplateRepository}.
 * The registry (`config('larafoundry-legal.pages')`) is the source of truth: it
 * declares every editable page's `slug` and its shipped placeholder title/body per
 * locale. The `larafoundry_legal_pages` table holds ONLY a super-admin's published
 * page, so an un-edited slug shows nothing publicly (fail-closed — a placeholder is
 * never served as real legal text) and an unregistered slug can neither be saved
 * nor shown (fail-closed too).
 *
 * The html sanitizer is REUSED from the email module on purpose: it is a generic,
 * email-and-document-friendly HTML allowlist, and keeping one sanitizer authority
 * avoids two drifting rulesets. The body is purified on write (so the database only
 * ever holds clean HTML) and again when resolved for public display as
 * defence-in-depth. The override map is cached (file/database driver — no Redis)
 * and busted on write, so a public page render is one cache hit, not a query.
 */
class LegalPageRepository
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * The registry of declared pages: slug => {title, body_html}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registry(): array
    {
        $registry = config('larafoundry-legal.pages', []);

        return is_array($registry) ? $registry : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $slug): ?array
    {
        $definition = $this->registry()[$slug] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function isRegistered(string $slug): bool
    {
        return $this->definition($slug) !== null;
    }

    /**
     * @return list<string>
     */
    public function availableLocales(): array
    {
        $locales = config('larafoundry.locale.available', ['en']);

        return is_array($locales) ? array_values($locales) : ['en'];
    }

    public function defaultLocale(): string
    {
        return (string) config('larafoundry.locale.default', 'en');
    }

    /**
     * Every registered page resolved for the index list: slug, whether it is
     * published, its version and whether a super-admin has customised it.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $overrides = $this->storedOverrides();

        $pages = [];
        foreach (array_keys($this->registry()) as $slug) {
            $override = $overrides[$slug] ?? null;
            $pages[] = [
                'slug' => $slug,
                'is_published' => (bool) ($override['is_published'] ?? false),
                'version' => (int) ($override['version'] ?? 0),
                'customized' => $override !== null,
            ];
        }

        return $pages;
    }

    /**
     * A single page resolved for the editor: the registry default with the stored
     * override merged over it, per locale. Null for an unregistered slug.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $definition = $this->definition($slug);

        if ($definition === null) {
            return null;
        }

        $override = $this->storedOverrides()[$slug] ?? null;

        return [
            'slug' => $slug,
            'title' => $this->mergeLocaleMap($definition['title'] ?? [], $override['title_translations'] ?? []),
            'body_html' => $this->mergeLocaleMap($definition['body_html'] ?? [], $override['body_html_translations'] ?? []),
            'version' => (int) ($override['version'] ?? 0),
            'is_published' => (bool) ($override['is_published'] ?? false),
        ];
    }

    /**
     * Resolve a page for PUBLIC display: the localized, purified title + body, or
     * NULL when the slug is unregistered or has not been published. A placeholder
     * default is never served — a page must be explicitly published first.
     *
     * @return array{slug: string, title: string, body_html: string, version: int}|null
     */
    public function publicPage(string $slug, ?string $locale): ?array
    {
        $resolved = $this->find($slug);

        if ($resolved === null || $resolved['is_published'] !== true) {
            return null;
        }

        $locale ??= $this->defaultLocale();

        return [
            'slug' => $slug,
            'title' => $this->localized($resolved['title'], $locale),
            'body_html' => $this->sanitizer->clean($this->localized($resolved['body_html'], $locale)),
            'version' => $resolved['version'],
        ];
    }

    /**
     * The version a user must have accepted for the slug to count as up to date,
     * or NULL when the page is unregistered or not published.
     *
     * The terms re-accept gate (phase 5.3 part B) reads this: NULL means "nothing
     * published, do not enforce" (fail-open), so the gate never locks users out of
     * a page that does not exist yet.
     */
    public function currentVersion(string $slug): ?int
    {
        $resolved = $this->find($slug);

        if ($resolved === null || $resolved['is_published'] !== true) {
            return null;
        }

        return $resolved['version'];
    }

    /**
     * Persist a super-admin's edit of a legal page.
     *
     * Throws on an unregistered slug (fail-closed). Every locale's html body is
     * purified before storage so the database only ever holds clean HTML. Version
     * handling: bump increments the stored version (the operator opts in when a
     * change is material enough to require re-acceptance); a published page always
     * carries a version of at least 1, so "accepted version 0" is always stale.
     *
     * @param  array{title: array<string,string>, body_html: array<string,string>, is_published?: bool, bump_version?: bool}  $data
     */
    public function save(string $slug, array $data): void
    {
        if (! $this->isRegistered($slug)) {
            throw new RuntimeException("Cannot edit unregistered legal page [{$slug}].");
        }

        $cleanHtml = [];
        foreach (($data['body_html'] ?? []) as $locale => $html) {
            $cleanHtml[$locale] = $this->sanitizer->clean((string) $html);
        }

        $existing = LegalPage::query()->where('slug', $slug)->first();

        $isPublished = (bool) ($data['is_published'] ?? false);
        $bump = (bool) ($data['bump_version'] ?? false);

        $version = $bump ? (int) ($existing->version ?? 0) + 1 : (int) ($existing->version ?? 0);
        if ($isPublished && $version < 1) {
            $version = 1;
        }

        // Stamp published_at when the page goes (or stays, with a bumped version)
        // live; an ordinary edit of an already-published page (e.g. a typo fix
        // without a version bump) keeps the original date, so it records when the
        // CURRENT version went live rather than the last save.
        $publishedAt = null;
        if ($isPublished) {
            $keepDate = (bool) ($existing->is_published ?? false) && ! $bump && $existing?->published_at !== null;
            $publishedAt = $keepDate ? $existing->published_at : Carbon::now();
        }

        LegalPage::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'title_translations' => $data['title'] ?? [],
                'body_html_translations' => $cleanHtml,
                'version' => $version,
                'is_published' => $isPublished,
                'published_at' => $publishedAt,
            ],
        );

        $this->forget();
    }

    /**
     * Sanitize a raw html body for the editor's live preview (unsaved input),
     * keeping one server-side sanitizer as the single authority.
     */
    public function preview(string $html): string
    {
        return $this->sanitizer->clean($html);
    }

    /**
     * Pick a locale map's value with fallback: requested locale, then the default
     * locale, then the first non-empty value, then empty string.
     *
     * @param  array<string, string>  $map
     */
    public function localized(array $map, string $locale): string
    {
        foreach ([$locale, $this->defaultLocale()] as $candidate) {
            if (isset($map[$candidate]) && $map[$candidate] !== '') {
                return (string) $map[$candidate];
            }
        }

        foreach ($map as $value) {
            if ($value !== '' && $value !== null) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Merge a stored override map over the registry default map, per locale.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    protected function mergeLocaleMap(array $defaults, mixed $overrides): array
    {
        return array_merge($defaults, is_array($overrides) ? $overrides : []);
    }

    /**
     * Every stored override keyed by slug, cached and busted on write.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function storedOverrides(): array
    {
        return Cache::rememberForever($this->cacheKey(), function () {
            return LegalPage::query()
                ->get()
                ->keyBy('slug')
                ->map(fn (LegalPage $page) => [
                    'title_translations' => $page->title_translations ?? [],
                    'body_html_translations' => $page->body_html_translations ?? [],
                    'version' => $page->version,
                    'is_published' => $page->is_published,
                ])
                ->all();
        });
    }

    protected function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function cacheKey(): string
    {
        return 'larafoundry.legal_pages';
    }
}
