<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Dmitryisaenko\LaraFoundry\Contracts\LocaleGeoResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and applies the active locale for the current request.
 *
 * One middleware for guests and authenticated users alike — the legacy host
 * split this into two near-duplicate classes. Resolution order:
 *
 *   1. authenticated user's stored preference (via HasLocalePreference)
 *   2. session
 *   3. cookie
 *   4. Accept-Language header (mapped through config)
 *   5. geo-IP resolver (optional, off by default — see config)
 *   6. configured default
 *
 * Every candidate is validated against the `available` whitelist before use.
 *
 * The resolved locale is applied inline to the current request via
 * App::setLocale(); there is no redirect or page reload. The choice is
 * persisted (session always, plus a year-long cookie when it was detected
 * rather than read back from session/cookie) so the next request is stable.
 *
 * Deliberately dropped from the legacy version:
 *   - synchronous `Http::get('ip-api.com')` on every guest request — moved
 *     behind the optional, opt-in {@see LocaleGeoResolver} contract;
 *   - injecting `<script>window.location.reload()</script>` into the response
 *     body — fragile and breaks Inertia. The locale now takes effect on the
 *     same request, so no client-side reload is needed.
 *
 * Config: `larafoundry.locale`.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = (array) config('larafoundry.locale.available', ['en']);
        $default = (string) config('larafoundry.locale.default', 'en');
        $cookieName = (string) config('larafoundry.locale.cookie', 'locale');

        $user = $request->user();

        // 1. Stored user preference — authoritative, no redirect needed.
        if ($user instanceof HasLocalePreference) {
            $preferred = $user->preferredLocale();

            if ($this->isAllowed($preferred, $available)) {
                App::setLocale($preferred);
                $request->session()->put('locale', $preferred);

                return $next($request);
            }
        }

        // 2. Session.
        $session = $request->session()->get('locale');
        if ($this->isAllowed($session, $available)) {
            App::setLocale($session);

            return $next($request);
        }

        // 3. Cookie.
        $cookie = $request->cookie($cookieName);
        if ($this->isAllowed($cookie, $available)) {
            App::setLocale($cookie);

            return $next($request);
        }

        // 4–6. Detect from request, persist, and (for guests) set the cookie.
        $resolved = $this->detect($request, $available) ?? $default;

        // Last-resort guard: never apply a locale outside the whitelist, even
        // if the configured default is itself misconfigured.
        if (! $this->isAllowed($resolved, $available)) {
            $resolved = $this->isAllowed($default, $available)
                ? $default
                : ($available[0] ?? 'en');
        }

        App::setLocale($resolved);
        $request->session()->put('locale', $resolved);

        if ($user instanceof HasLocalePreference && $user->preferredLocale() !== $resolved) {
            $user->setPreferredLocale($resolved);
        }

        $response = $next($request);

        // Persist the detected locale as a cookie for the next request — but only
        // if the request did not already set this cookie itself. The language
        // switch endpoint runs *inside* this middleware: on a guest's first
        // switch there is no session/cookie locale yet, so resolution lands here
        // (detect → default) and would otherwise overwrite the controller's
        // freshly chosen cookie. A downstream choice is authoritative; never
        // clobber it with the detected fallback.
        if (! $this->responseAlreadySetsCookie($response, $cookieName)) {
            $response->headers->setCookie(cookie($cookieName, $resolved, 60 * 24 * 365));
        }

        return $response;
    }

    /**
     * Whether the response (or the queued-cookie jar) already carries this cookie.
     *
     * Covers both ways a downstream handler can set a cookie: attaching it to the
     * response headers directly (e.g. RedirectResponse::withCookie) and queuing it
     * through the CookieJar, which Laravel flushes onto the response after this
     * middleware returns.
     */
    protected function responseAlreadySetsCookie(Response $response, string $name): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return true;
            }
        }

        // Also catch cookies the downstream queued via the CookieJar — those are
        // flushed onto the response only after this middleware returns.
        return app()->bound('cookie') && app('cookie')->hasQueued($name);
    }

    /**
     * Detect a locale from the browser header, then the optional geo resolver.
     *
     * Browser resolution is identity-first: the two-letter Accept-Language code
     * is taken as-is when it is one of the available locales. Only genuinely
     * non-identical cases (e.g. ru → uk) live in `detect_map`; the common
     * en → en / uk → uk noise is gone.
     *
     * @param  array<int, string>  $available
     */
    protected function detect(Request $request, array $available): ?string
    {
        $header = (string) $request->server('HTTP_ACCEPT_LANGUAGE', '');
        $browserLang = strtolower(substr($header, 0, 2));

        // 1. Identity: the browser code is itself an available locale.
        if ($this->isAllowed($browserLang, $available)) {
            return $browserLang;
        }

        // 2. Exceptions only: a configured non-identical mapping.
        $map = (array) config('larafoundry.locale.detect_map', []);
        $fromMap = $map[$browserLang] ?? null;

        if ($this->isAllowed($fromMap, $available)) {
            return $fromMap;
        }

        if (config('larafoundry.locale.geoip.enabled', false)) {
            $resolver = $this->geoResolver();

            if ($resolver !== null) {
                $fromGeo = $resolver->resolve((string) $request->ip(), $available);

                if ($this->isAllowed($fromGeo, $available)) {
                    return $fromGeo;
                }
            }
        }

        return null;
    }

    protected function geoResolver(): ?LocaleGeoResolver
    {
        $class = config('larafoundry.locale.geoip.resolver');

        if ($class === null) {
            return null;
        }

        $resolver = app($class);

        return $resolver instanceof LocaleGeoResolver ? $resolver : null;
    }

    /**
     * @param  array<int, string>  $available
     */
    protected function isAllowed(?string $locale, array $available): bool
    {
        return $locale !== null && $locale !== '' && in_array($locale, $available, true);
    }
}
