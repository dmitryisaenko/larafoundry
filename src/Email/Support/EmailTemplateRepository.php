<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Support;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Notifications\Messages\MailMessage;
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
     * A registry (transactional) code reads its whitelist from the config; a
     * marketing code carries its OWN whitelist on the DB row.
     *
     * @return list<string>
     */
    public function variablesFor(string $code): array
    {
        if ($this->isRegistered($code)) {
            $variables = $this->definition($code)['variables'] ?? [];

            return is_array($variables) ? array_values($variables) : [];
        }

        $row = $this->marketingRow($code);

        return $row !== null ? array_values($row['variables']) : [];
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
     * Every template resolved for the index list, ACROSS BOTH LAYERS: the registry
     * (transactional) codes plus the marketing DB rows. Each entry carries its
     * `type`, `name` (label), variable whitelist, active flag, whether it is
     * customised and whether it is deletable (marketing = yes, transactional = no).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $overrides = $this->storedOverrides();

        $templates = [];
        foreach (array_keys($this->registry()) as $code) {
            $override = $overrides[$code] ?? null;
            $templates[] = [
                'code' => $code,
                'type' => 'transactional',
                'name' => null,
                'variables' => $this->variablesFor($code),
                'is_active' => $override['is_active'] ?? true,
                'customized' => $override !== null,
                'deletable' => false,
            ];
        }

        foreach ($overrides as $code => $row) {
            if (($row['type'] ?? 'transactional') !== 'marketing') {
                continue;
            }
            $templates[] = [
                'code' => $code,
                'type' => 'marketing',
                'name' => $row['name'],
                'variables' => array_values($row['variables']),
                'is_active' => $row['is_active'] ?? true,
                'customized' => true,
                'deletable' => true,
            ];
        }

        return $templates;
    }

    /**
     * A single template resolved for the editor, in a shape uniform across layers.
     *
     * A registry (transactional) code → the registry default with the stored
     * override merged over it, per locale. A marketing code (in the DB only, not
     * in config) → resolved entirely from its self-contained row. Null when the
     * code is neither a registry code nor a marketing row.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $definition = $this->definition($code);

        if ($definition !== null) {
            $override = $this->storedOverrides()[$code] ?? null;

            return [
                'code' => $code,
                'type' => 'transactional',
                'name' => null,
                'variables' => $this->variablesFor($code),
                'is_active' => $override['is_active'] ?? true,
                'subject' => $this->mergeLocaleMap($definition['subject'] ?? [], $override['subject_translations'] ?? []),
                'body_html' => $this->mergeLocaleMap($definition['body_html'] ?? [], $override['body_html_translations'] ?? []),
                'body_text' => $this->mergeLocaleMap($definition['body_text'] ?? [], $override['body_text_translations'] ?? []),
            ];
        }

        $row = $this->marketingRow($code);

        if ($row === null) {
            return null;
        }

        return [
            'code' => $code,
            'type' => 'marketing',
            'name' => $row['name'],
            'variables' => array_values($row['variables']),
            'is_active' => $row['is_active'] ?? true,
            'subject' => $row['subject_translations'],
            'body_html' => $row['body_html_translations'],
            'body_text' => $row['body_text_translations'],
        ];
    }

    /**
     * The cached row for a MARKETING code (self-contained, no registry entry), or
     * null when the code is not a marketing row.
     *
     * @return array<string, mixed>|null
     */
    protected function marketingRow(string $code): ?array
    {
        $row = $this->storedOverrides()[$code] ?? null;

        return ($row !== null && ($row['type'] ?? 'transactional') === 'marketing') ? $row : null;
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
     * Build a ready-to-return MailMessage for a notification from a template, or
     * NULL to signal the caller to fall back to its own static MailMessage
     * (decision D-5.1-8). This is the single seam the core's mail notifications
     * use (verify-email, password-reset, welcome, company-invitation).
     *
     * Returns a MailMessage rather than a Mailable on purpose: the notification's
     * MailChannel still owns addressing, which works for an ON-DEMAND notifiable
     * (a company invite sent to a raw email with no user account) as well as a
     * User. The rendered html is already purified by {@see self::render()}; the
     * `larafoundry::mail` views echo the html/text parts raw.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function mailMessage(string $code, ?string $locale, array $data): ?MailMessage
    {
        $rendered = $this->render($code, $locale, $data);

        if ($rendered === null) {
            return null;
        }

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->view(
                ['larafoundry::mail.html', 'larafoundry::mail.text'],
                ['html' => $rendered['html'], 'text' => $rendered['text']],
            );
    }

    /**
     * Persist an edit of an existing template, on either layer.
     *
     * FAIL-CLOSED: throws unless the code is a registry (transactional) code OR an
     * existing marketing row — a bare unregistered code can neither be saved nor
     * created here (that is what {@see self::createMarketing()} is for, and it can
     * never mint a transactional code). A registry code is always written back as
     * `type=transactional` (its type can never be flipped); a marketing edit also
     * updates the row's own `name` and `variables`. The html body of every locale
     * is purified before storage so the database only ever holds clean HTML; the
     * caller (FormRequest) has already enforced the right variable whitelist.
     *
     * @param  array{is_active?: bool, name?: ?string, variables?: list<string>, subject: array<string,string>, body_html: array<string,string>, body_text: array<string,string>}  $data
     */
    public function save(string $code, array $data): void
    {
        $isRegistered = $this->isRegistered($code);
        $marketing = $this->marketingRow($code);

        if (! $isRegistered && $marketing === null) {
            throw new RuntimeException("Cannot edit unregistered email template [{$code}].");
        }

        $attributes = [
            'subject_translations' => $data['subject'] ?? [],
            'body_html_translations' => $this->cleanHtmlMap($data['body_html'] ?? []),
            'body_text_translations' => $data['body_text'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ];

        if ($isRegistered) {
            // A registry code is always transactional; never let its type flip.
            $attributes['type'] = 'transactional';
        } else {
            $attributes['type'] = 'marketing';
            $attributes['name'] = $data['name'] ?? $marketing['name'];
            $attributes['variables'] = array_values($data['variables'] ?? $marketing['variables']);
        }

        EmailTemplate::query()->updateOrCreate(['code' => $code], $attributes);

        $this->forget();
    }

    /**
     * Create a self-contained MARKETING template.
     *
     * FAIL-CLOSED against the transactional layer: refuses a code that collides
     * with a registry code (a marketing row must never shadow a code-driven
     * sender) or with an existing DB row. The row carries its OWN variable
     * whitelist; the html body is purified on write.
     *
     * @param  array{code: string, name?: ?string, variables?: list<string>, is_active?: bool, subject?: array<string,string>, body_html?: array<string,string>, body_text?: array<string,string>}  $data
     */
    public function createMarketing(array $data): EmailTemplate
    {
        $code = (string) $data['code'];

        if ($this->isRegistered($code)) {
            throw new RuntimeException("Cannot create a marketing template with the reserved transactional code [{$code}].");
        }

        if (EmailTemplate::query()->where('code', $code)->exists()) {
            throw new RuntimeException("An email template with code [{$code}] already exists.");
        }

        $template = EmailTemplate::query()->create([
            'code' => $code,
            'type' => 'marketing',
            'name' => $data['name'] ?? $code,
            'variables' => array_values($data['variables'] ?? []),
            'subject_translations' => $data['subject'] ?? [],
            'body_html_translations' => $this->cleanHtmlMap($data['body_html'] ?? []),
            'body_text_translations' => $data['body_text'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->forget();

        return $template;
    }

    /**
     * Clone ANY template (registry or marketing) into a NEW marketing row.
     *
     * The copy is ALWAYS marketing and carries its OWN variable whitelist copied
     * from the source, so duplicating a transactional code never forks it into a
     * second code-driven sender — the original registry code is untouched and
     * keeps sending through its Notification. A fresh unique code is derived (or
     * supplied via `$overrides['code']`).
     *
     * @param  array{code?: string, name?: string}  $overrides
     */
    public function duplicate(string $sourceCode, array $overrides = []): EmailTemplate
    {
        $source = $this->find($sourceCode);

        if ($source === null) {
            throw new RuntimeException("Cannot duplicate unknown email template [{$sourceCode}].");
        }

        $newCode = (string) ($overrides['code'] ?? $this->deriveCopyCode($sourceCode));
        $sourceLabel = $source['name'] ?? $sourceCode;

        return $this->createMarketing([
            'code' => $newCode,
            'name' => $overrides['name'] ?? ($sourceLabel.' (copy)'),
            'variables' => $source['variables'],
            'is_active' => $source['is_active'],
            'subject' => $source['subject'],
            'body_html' => $source['body_html'],
            'body_text' => $source['body_text'],
        ]);
    }

    /**
     * Delete a MARKETING template.
     *
     * FAIL-CLOSED: refuses to delete a registry (transactional) code — its
     * Notification must never lose the template out from under it — and refuses a
     * row whose type is not marketing. Only self-contained marketing rows go.
     */
    public function deleteMarketing(string $code): void
    {
        if ($this->isRegistered($code)) {
            throw new RuntimeException("Cannot delete the transactional email template [{$code}].");
        }

        $row = EmailTemplate::query()->where('code', $code)->first();

        if ($row === null || $row->type !== 'marketing') {
            throw new RuntimeException("Cannot delete non-marketing email template [{$code}].");
        }

        $row->delete();

        $this->forget();
    }

    /**
     * Purify every locale's html body before storage (email-friendly purifier).
     *
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    protected function cleanHtmlMap(array $map): array
    {
        $clean = [];
        foreach ($map as $locale => $html) {
            $clean[$locale] = $this->sanitizer->clean((string) $html);
        }

        return $clean;
    }

    /**
     * A fresh unique code for a duplicate, unique across BOTH registry codes and
     * DB rows: `{base}_copy`, then `_copy_2`, `_copy_3`, …
     *
     * The base is clamped so the final code (including the longest suffix that may
     * be appended) never exceeds the 255-char `code` column — a near-255-char
     * source would otherwise overflow and raise a raw DB error on strict engines.
     */
    protected function deriveCopyCode(string $base): string
    {
        // Reserve room for the widest suffix we may append (`_copy_<n>`); 20 chars
        // covers `_copy_` plus a very large counter, leaving the base <= 235.
        $base = mb_substr($base, 0, 255 - 20);

        $candidate = $base.'_copy';
        $suffix = 1;

        while ($this->isRegistered($candidate) || EmailTemplate::query()->where('code', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'_copy_'.$suffix;
        }

        return $candidate;
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
        return $this->sampleDataForVariables($this->variablesFor($code));
    }

    /**
     * Sample values for an ARBITRARY variable list — used by the marketing draft
     * preview (a template that is not persisted yet has no code to resolve).
     *
     * @param  list<string>  $variables
     * @return array<string, string>
     */
    public function sampleDataForVariables(array $variables): array
    {
        $appName = (string) config('app.name', 'LaraFoundry');

        $known = [
            'app_name' => $appName,
            'name' => 'Jane Doe',
            'inviter_name' => 'John Smith',
            'owner_name' => 'John Smith',
            'member_name' => 'Jane Doe',
            'invited_email' => 'jane@example.com',
            'company_name' => 'Acme Inc.',
            'login_url' => url('/login'),
            'support_url' => url('/support'),
            'reset_url' => url('/password/reset/sample-token'),
            'verification_url' => url('/email/verify/sample'),
            'accept_url' => url('/invitations/sample/accept'),
            'expires_at' => now()->addDays(7)->toDayDateTimeString(),
        ];

        $data = [];
        foreach ($variables as $variable) {
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
                    'type' => $template->type ?? 'transactional',
                    'name' => $template->name,
                    'variables' => $template->variables ?? [],
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
