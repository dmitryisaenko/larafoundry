<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Core Inertia shared-props middleware.
 *
 * Shares only cross-cutting infrastructure every page needs: flash messages,
 * the active locale, its translation bag, Ziggy routing and the appearance
 * preference. This is the backend half of the core contract consumed by the
 * frontend (`app.js` reads `locale` + `translations` to boot vue-i18n; the
 * `flash` shape feeds AppFlashMessage; `ziggy` powers `route()`).
 *
 * Host apps extend this class and merge their own props (auth, tenancy,
 * navigation, business data):
 *
 *     class HandleInertiaRequests extends \Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests
 *     {
 *         public function share(Request $request): array
 *         {
 *             return [
 *                 ...parent::share($request),
 *                 'auth' => fn () => $request->user(),
 *                 // …
 *             ];
 *         }
 *     }
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => fn () => $this->flash($request),
            'locale' => fn () => App::getLocale(),
            'available_locales' => fn () => $this->availableLocales(),
            'translations' => fn () => $this->translations(),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'appearance' => fn () => $request->cookie('appearance') ?? 'system',
            'auth_presentation' => fn () => $this->authPresentation(),
            'auth_qr' => fn () => $this->qrSettings(),
            'auth_oauth' => fn () => $this->oauthSettings(),
            'consent' => fn () => $this->consentState($request),
        ];
    }

    /**
     * Consent state the frontend needs cross-page (phase 5.3 part B): whether the
     * cookie banner is enabled and already decided (the banner reads this on every
     * page), and whether the registration checkbox is required (cheap, resolved
     * from the published Terms version). Both come from the one ConsentManager so
     * the host wires nothing.
     *
     * @return array<string, mixed>
     */
    protected function consentState(Request $request): array
    {
        $consent = app(ConsentManager::class);

        return [
            'cookie' => [
                'enabled' => $consent->cookieConsentEnabled(),
                'decided' => $consent->cookieConsentDecided($request),
            ],
            'terms_required' => $consent->termsRequired(),
        ];
    }

    /**
     * How the guest auth screens are surfaced: `page` (default) or `modal`.
     *
     * Validated against the known set so a host typo (or a missing config key)
     * degrades to `page` rather than rendering an undefined surface. This is the
     * single source of truth the AuthScreen wrapper reads on the frontend.
     */
    protected function authPresentation(): string
    {
        $mode = config('larafoundry.auth.presentation', 'page');

        return in_array($mode, ['page', 'modal'], true) ? $mode : 'page';
    }

    /**
     * QR cross-device login settings the Login screen needs: whether to show the
     * QR tab and how often the panel should poll for approval.
     *
     * @return array{enabled: bool, poll_interval_ms: int}
     */
    protected function qrSettings(): array
    {
        return [
            'enabled' => (bool) config('larafoundry.auth.qr.enabled', true),
            'poll_interval_ms' => (int) config('larafoundry.auth.qr.poll_interval_ms', 2000),
        ];
    }

    /**
     * Social sign-in settings the auth screens need to render OAuth buttons:
     * whether the feature is on and the blessed provider allow-list. Driven
     * entirely by `larafoundry.auth.oauth.*` so adding a provider in config
     * surfaces a button with no frontend change, and the rendered set never
     * drifts from what the OAuthController will actually accept.
     *
     * `providers` is filtered to strings so a host typo degrades that one entry
     * rather than fatalling a shared prop sent on every response.
     *
     * @return array{enabled: bool, providers: array<int, string>}
     */
    protected function oauthSettings(): array
    {
        return [
            'enabled' => (bool) config('larafoundry.auth.oauth.enabled', false),
            'providers' => array_values(array_filter(
                (array) config('larafoundry.auth.oauth.providers', []),
                'is_string',
            )),
        ];
    }

    /**
     * One-shot flash payload pulled from the session.
     *
     * @return array<string, mixed>
     */
    protected function flash(Request $request): array
    {
        $session = $request->session();

        return [
            'info' => $session->pull('message-info'),
            'error' => $session->pull('message-error'),
            'status' => $session->pull('status'),
            'disappear_info' => $session->pull('message-disappear-info'),
            'disappear_error' => $session->pull('message-disappear-error'),
        ];
    }

    /**
     * Translation bag for the active locale, sent to vue-i18n.
     *
     * Layered so a host always wins over the core:
     *   1. the core's bundled frontend dictionary (`lang/frontend/{locale}.json`),
     *      shipping the core pages' UI strings (English text as the key) out of
     *      the box for the locales the core supports (en, uk);
     *   2. the host's `lang/{locale}.json` (overrides core strings, adds its own);
     *   3. the host's `lang/{locale}/*.php` groups, matching Laravel's loader
     *      layout so existing files work unchanged.
     *
     * Later layers overwrite earlier ones on key collision, so a host can retune
     * any core string without touching the package.
     *
     * @return array<string, mixed>
     */
    protected function translations(): array
    {
        $locale = App::getLocale();

        $data = $this->coreFrontendStrings($locale);

        $jsonPath = base_path("lang/{$locale}.json");
        if (File::exists($jsonPath)) {
            $json = json_decode((string) File::get($jsonPath), true);
            if (is_array($json)) {
                $data = [...$data, ...$json];
            }
        }

        $dir = base_path("lang/{$locale}");
        if (File::isDirectory($dir)) {
            foreach (File::files($dir) as $file) {
                $group = $file->getFilenameWithoutExtension();
                $data[$group] = Lang::get($group);
            }
        }

        return $data;
    }

    /**
     * Per-locale memo of the core's bundled frontend dictionary, so the file is
     * read and decoded at most once per locale per request rather than on every
     * shared-prop evaluation.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $coreFrontendCache = [];

    /**
     * The core's bundled frontend dictionary for a locale, or an empty array
     * when the core does not ship one (the host then supplies everything).
     *
     * The bundled file is immutable for the life of a deploy, so the decoded
     * result is memoized per locale (see {@see $coreFrontendCache}).
     *
     * @return array<string, mixed>
     */
    protected function coreFrontendStrings(string $locale): array
    {
        if (isset(static::$coreFrontendCache[$locale])) {
            return static::$coreFrontendCache[$locale];
        }

        $path = __DIR__."/../../../lang/frontend/{$locale}.json";

        if (! File::exists($path)) {
            return static::$coreFrontendCache[$locale] = [];
        }

        $json = json_decode((string) File::get($path), true);

        return static::$coreFrontendCache[$locale] = is_array($json) ? $json : [];
    }

    /**
     * The locales offered to the language switcher: each available code paired
     * with its display metadata (native name, flag). Codes without metadata fall
     * back to the bare code, so the switcher never hides a usable locale.
     *
     * Non-string entries in `available` are dropped rather than fatalled: this is
     * a shared prop on every response, so a host typo must degrade the switcher,
     * not 500 the whole app (the same tolerance SetLocale applies to the list).
     *
     * @return array<int, array{code: string, native: string, flag: ?string}>
     */
    protected function availableLocales(): array
    {
        $available = array_filter(
            (array) config('larafoundry.locale.available', ['en']),
            'is_string',
        );
        $meta = (array) config('larafoundry.locale.locales', []);

        return array_map(static fn (string $code): array => [
            'code' => $code,
            'native' => $meta[$code]['native'] ?? $code,
            'flag' => $meta[$code]['flag'] ?? null,
        ], array_values($available));
    }
}
