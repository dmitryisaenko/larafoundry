<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Support;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The email-template store: a config-registry of editable templates with an
 * optional per-template database override (phase 5.1).
 *
 * Mirrors {@see SettingsRepository}.
 * The registry (`config('larafoundry-email.templates')`) is the source of truth:
 * it declares every editable template's `code`, its variable whitelist and its
 * shipped default subject/body per locale. The `larafoundry_email_templates`
 * table holds ONLY a super-admin's overrides, so a template renders from its
 * registry default until edited (graceful, decision D-5.1-8) and an unregistered
 * code can neither be saved nor sent (fail-closed, decision D-5.1-10).
 *
 * Every resolved value is the registry default with the stored override merged
 * over it, per locale. The override map is cached (file/database driver — no
 * Redis) and busted on write, so a render is one cache hit, not a query.
 */
class EmailTemplateRepository
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * The registry of declared templates: code => {variables, subject, ...}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registry(): array
    {
        $registry = config('larafoundry-email.templates', []);

        return is_array($registry) ? $registry : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $code): ?array
    {
        $definition = $this->registry()[$code] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function isRegistered(string $code): bool
    {
        return $this->definition($code) !== null;
    }

    /**
     * The whitelist of variable names allowed in a template (STRICT, D-5.1-11).
     *
     * @return list<string>
     */
    public function variablesFor(string $code): array
    {
        $variables = $this->definition($code)['variables'] ?? [];

        return is_array($variables) ? array_values($variables) : [];
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
     * Every registered template resolved for the index list: code, active flag,
     * whether an override exists, and the variable whitelist.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $overrides = $this->storedOverrides();

        $templates = [];
        foreach ($this->registry() as $code => $definition) {
            $override = $overrides[$code] ?? null;
            $templates[] = [
                'code' => $code,
                'variables' => $this->variablesFor($code),
                'is_active' => $override['is_active'] ?? true,
                'customized' => $override !== null,
            ];
        }

        return $templates;
    }

    /**
     * A single template resolved for the editor: the registry default with the
     * stored override merged over it, per locale. Null for an unregistered code.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $definition = $this->definition($code);

        if ($definition === null) {
            return null;
        }

        $override = $this->storedOverrides()[$code] ?? null;

        return [
            'code' => $code,
            'variables' => $this->variablesFor($code),
            'is_active' => $override['is_active'] ?? true,
            'subject' => $this->mergeLocaleMap($definition['subject'] ?? [], $override['subject_translations'] ?? []),
            'body_html' => $this->mergeLocaleMap($definition['body_html'] ?? [], $override['body_html_translations'] ?? []),
            'body_text' => $this->mergeLocaleMap($definition['body_text'] ?? [], $override['body_text_translations'] ?? []),
        ];
    }

    /**
     * Render a template for delivery (phase-5.3/D consumers).
     *
     * Returns the rendered subject + html + text for the locale (with fallback),
     * or NULL when the code is unregistered or the template is switched off — the
     * caller then falls back to its own static MailMessage (decision D-5.1-8). The
     * body is purified again here as defence-in-depth (registry defaults are
     * trusted, stored overrides were cleaned on write, but a second pass is cheap
     * and closes the gap if either assumption ever fails).
     *
     * @param  array<string, scalar|null>  $data
     * @return array{subject: string, html: string, text: string}|null
     */
    public function render(string $code, ?string $locale, array $data): ?array
    {
        $resolved = $this->find($code);

        if ($resolved === null || $resolved['is_active'] !== true) {
            return null;
        }

        $locale ??= $this->defaultLocale();

        return [
            'subject' => $this->renderer->render($this->localized($resolved['subject'], $locale), $data),
            'html' => $this->sanitizer->clean($this->renderer->render($this->localized($resolved['body_html'], $locale), $data)),
            'text' => $this->renderer->render($this->localized($resolved['body_text'], $locale), $data),
        ];
    }

    /**
     * Persist a super-admin's override of a template.
     *
     * Throws on an unregistered code (fail-closed). The html body of every locale
     * is purified before storage so the database only ever holds clean HTML. The
     * caller (FormRequest) has already enforced the variable whitelist.
     *
     * @param  array{is_active?: bool, subject: array<string,string>, body_html: array<string,string>, body_text: array<string,string>}  $data
     */
    public function save(string $code, array $data): void
    {
        if (! $this->isRegistered($code)) {
            throw new RuntimeException("Cannot edit unregistered email template [{$code}].");
        }

        $cleanHtml = [];
        foreach (($data['body_html'] ?? []) as $locale => $html) {
            $cleanHtml[$locale] = $this->sanitizer->clean((string) $html);
        }

        EmailTemplate::query()->updateOrCreate(
            ['code' => $code],
            [
                'subject_translations' => $data['subject'] ?? [],
                'body_html_translations' => $cleanHtml,
                'body_text_translations' => $data['body_text'] ?? [],
                'is_active' => $data['is_active'] ?? true,
            ],
        );

        $this->forget();
    }

    /**
     * Sample values for the preview / test email — one per whitelisted variable,
     * so an operator sees a realistic render without real recipient data. Known
     * infrastructure variables get meaningful samples; the rest get a labelled
     * placeholder.
     *
     * @return array<string, string>
     */
    public function sampleData(string $code): array
    {
        $appName = (string) config('app.name', 'LaraFoundry');

        $known = [
            'app_name' => $appName,
            'name' => 'Jane Doe',
            'inviter_name' => 'John Smith',
            'company_name' => 'Acme Inc.',
            'login_url' => url('/login'),
            'support_url' => url('/support'),
            'reset_url' => url('/password/reset/sample-token'),
            'verification_url' => url('/email/verify/sample'),
            'accept_url' => url('/invitations/sample/accept'),
            'expires_at' => now()->addDays(7)->toDayDateTimeString(),
        ];

        $data = [];
        foreach ($this->variablesFor($code) as $variable) {
            $data[$variable] = $known[$variable] ?? '['.$variable.']';
        }

        return $data;
    }

    /**
     * Pick a locale map's value with fallback: requested locale, then the default
     * locale, then the first non-empty value, then empty string.
     *
     * A present-but-empty value for the requested locale falls through to the
     * fallbacks (rather than returning ''), so a partially-filled override map
     * still renders something.
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
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function mergeLocaleMap(array $defaults, mixed $overrides): array
    {
        return array_merge($defaults, is_array($overrides) ? $overrides : []);
    }

    /**
     * Every stored override keyed by code, cached and busted on write.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function storedOverrides(): array
    {
        return Cache::rememberForever($this->cacheKey(), function () {
            return EmailTemplate::query()
                ->get()
                ->keyBy('code')
                ->map(fn (EmailTemplate $template) => [
                    'subject_translations' => $template->subject_translations ?? [],
                    'body_html_translations' => $template->body_html_translations ?? [],
                    'body_text_translations' => $template->body_text_translations ?? [],
                    'is_active' => $template->is_active,
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
        return 'larafoundry.email_templates';
    }
}
