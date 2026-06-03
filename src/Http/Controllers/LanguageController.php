<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use Dmitryisaenko\LaraFoundry\Rules\ValidLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

/**
 * Records the user's explicit language choice.
 *
 * This only *persists* the choice; {@see SetLocale}
 * reads it back and applies it on the next request. The two must agree on
 * precedence, which is why an authenticated user's choice is written to the
 * database: SetLocale treats the stored preference as authoritative (step 1), so
 * persisting only the session/cookie would let the old DB value win on the very
 * next request and bounce the choice back.
 *
 * Precedence written here, mirroring SetLocale's read order:
 *   - guests:        session + a year-long cookie;
 *   - authenticated: the above plus the stored DB preference (authoritative).
 *
 * The submitted locale is validated against the `available` whitelist, so no
 * untrusted string ever reaches the session, cookie or database. The redirect
 * back is constrained to the application's own host, so a forged Referer cannot
 * turn the switch into an open redirect.
 */
class LanguageController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = Validator::make(
            $request->all(),
            ['locale' => ['required', 'string', new ValidLocale]],
        )->validate();

        $locale = $validated['locale'];
        $cookieName = (string) config('larafoundry.locale.cookie', 'locale');

        $request->session()->put('locale', $locale);

        $user = $request->user();
        if ($user instanceof HasLocalePreference) {
            $user->setPreferredLocale($locale);
        }

        return Redirect::to($this->safeReturnUrl($request))
            ->withCookie(cookie($cookieName, $locale, 60 * 24 * 365));
    }

    /**
     * Where to send the user back after switching.
     *
     * `back()` falls back to the Referer header when the session has no stored
     * previous URL, and the Referer is attacker-controlled — switching language
     * must never become an open redirect. So the previous URL is accepted only
     * when it points at this application's own host; otherwise we fall back to
     * the app root.
     */
    protected function safeReturnUrl(Request $request): string
    {
        $previous = URL::previous();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $previousHost = parse_url($previous, PHP_URL_HOST);

        // Relative URLs (no host) and same-host URLs are safe; anything else is not.
        if ($previousHost === null || $previousHost === $appHost) {
            return $previous;
        }

        return URL::to('/');
    }
}
