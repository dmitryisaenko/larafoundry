<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk', 'de']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry.locale.cookie', 'locale');
    config()->set('larafoundry.locale.detect_map', ['ru' => 'uk']); // exceptions only
    config()->set('larafoundry.locale.geoip.enabled', false);
    config()->set('larafoundry.locale.geoip.resolver', null);
});

function localeNext(): Closure
{
    return fn (Request $request): Response => new Response('ok');
}

/** Request with a started session, optionally seeded with a session locale. */
function localeRequest(array $cookies = [], ?string $sessionLocale = null, array $server = []): Request
{
    $request = Request::create('/', 'GET', cookies: $cookies, server: $server);
    $session = app('session')->driver();
    if ($sessionLocale !== null) {
        $session->put('locale', $sessionLocale);
    }
    $request->setLaravelSession($session);

    return $request;
}

/** A throwaway user carrying a locale preference. */
function userWithLocale(?string $locale): Authenticatable&HasLocalePreference
{
    return new class($locale) implements Authenticatable, HasLocalePreference
    {
        public ?string $saved = null;

        public function __construct(private ?string $locale) {}

        public function preferredLocale(): ?string
        {
            return $this->locale;
        }

        public function setPreferredLocale(string $locale): void
        {
            $this->locale = $locale;
            $this->saved = $locale;
        }

        public function getAuthIdentifierName()
        {
            return 'id';
        }

        public function getAuthIdentifier()
        {
            return 1;
        }

        public function getAuthPassword()
        {
            return '';
        }

        public function getAuthPasswordName()
        {
            return 'password';
        }

        public function getRememberToken()
        {
            return '';
        }

        public function setRememberToken($value) {}

        public function getRememberTokenName()
        {
            return '';
        }
    };
}

it('prefers the authenticated user stored locale', function () {
    $request = localeRequest(sessionLocale: 'de');
    $request->setUserResolver(fn () => userWithLocale('uk'));

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('uk');
});

it('ignores a user locale that is not whitelisted and falls through', function () {
    $request = localeRequest(sessionLocale: 'de');
    $request->setUserResolver(fn () => userWithLocale('fr')); // not allowed

    (new SetLocale)->handle($request, localeNext());

    // Falls through to the session locale.
    expect(App::getLocale())->toBe('de');
});

it('uses the session locale for a guest', function () {
    $request = localeRequest(sessionLocale: 'uk');

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('uk');
});

it('uses the cookie locale when there is no session locale', function () {
    $request = localeRequest(cookies: ['locale' => 'de']);

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('de');
});

it('detects locale from the Accept-Language header by identity', function () {
    $request = localeRequest(server: ['HTTP_ACCEPT_LANGUAGE' => 'uk-UA,uk;q=0.9']);

    $response = (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('uk');
    // Guests get the resolved locale persisted as a cookie.
    expect($response->headers->getCookies())->not->toBeEmpty();
});

it('maps a non-identical browser code through detect_map (ru → uk)', function () {
    $request = localeRequest(server: ['HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9']);

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('uk');
});

it('falls back to the default locale when nothing matches', function () {
    $request = localeRequest(server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('en');
});

it('persists a freshly detected locale onto the user', function () {
    $user = userWithLocale(null);
    $request = localeRequest(server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
    $request->setUserResolver(fn () => $user);

    (new SetLocale)->handle($request, localeNext());

    expect(App::getLocale())->toBe('de');
    expect($user->saved)->toBe('de');
});

it('never sets a locale outside the whitelist even with a misconfigured default', function () {
    config()->set('larafoundry.locale.default', 'zz'); // bogus default, not whitelisted
    $request = localeRequest(server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);

    (new SetLocale)->handle($request, localeNext());

    // Falls back to the first whitelisted locale rather than applying 'zz'.
    expect(App::getLocale())->toBe('en');
});
