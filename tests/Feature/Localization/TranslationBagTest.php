<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.locales', [
        'en' => ['native' => 'English', 'flag' => '🇬🇧'],
        'uk' => ['native' => 'Українська', 'flag' => '🇺🇦'],
    ]);
});

/** Exposes the middleware's protected translation/locale helpers for assertion. */
function inertiaMiddleware(): HandleInertiaRequests
{
    return new class extends HandleInertiaRequests
    {
        public function exposeTranslations(): array
        {
            // Per-locale memo is static; clear it so each test reads fresh state.
            self::$coreFrontendCache = [];

            return $this->translations();
        }

        public function exposeAvailableLocales(): array
        {
            return $this->availableLocales();
        }
    };
}

it('ships the core frontend dictionary for ukrainian out of the box', function () {
    App::setLocale('uk');

    $bag = inertiaMiddleware()->exposeTranslations();

    // A core page string is translated even with no host lang file present.
    expect($bag['Employees'])->toBe('Співробітники');
    expect($bag['Create your company'])->toBe('Створіть свою компанію');
});

it('falls back to the english key text when the core ships no translation', function () {
    App::setLocale('en');

    $bag = inertiaMiddleware()->exposeTranslations();

    // The english dictionary maps each key to its own english text.
    expect($bag['Employees'])->toBe('Employees');
});

it('lets the host override a core string', function () {
    App::setLocale('uk');

    $langDir = base_path('lang');
    File::ensureDirectoryExists($langDir);
    File::put($langDir.'/uk.json', json_encode(['Employees' => 'Працівники компанії']));

    try {
        $bag = inertiaMiddleware()->exposeTranslations();

        // Host value wins over the core's bundled "Співробітники".
        expect($bag['Employees'])->toBe('Працівники компанії');
        // Core strings the host did not override are still present.
        expect($bag['Create your company'])->toBe('Створіть свою компанію');
    } finally {
        File::delete($langDir.'/uk.json');
    }
});

it('builds the available-locales list with display metadata', function () {
    $locales = inertiaMiddleware()->exposeAvailableLocales();

    expect($locales)->toBe([
        ['code' => 'en', 'native' => 'English', 'flag' => '🇬🇧'],
        ['code' => 'uk', 'native' => 'Українська', 'flag' => '🇺🇦'],
    ]);
});

it('falls back to the bare code when a locale has no metadata', function () {
    config()->set('larafoundry.locale.available', ['en', 'de']);

    $locales = inertiaMiddleware()->exposeAvailableLocales();

    expect($locales)->toContain(['code' => 'de', 'native' => 'de', 'flag' => null]);
});

it('drops a non-string available entry instead of fatalling the shared prop', function () {
    // A host typo (nested array / int) must degrade the switcher, not 500 every page.
    config()->set('larafoundry.locale.available', ['en', ['uk'], 'de']);

    $locales = inertiaMiddleware()->exposeAvailableLocales();

    expect(array_column($locales, 'code'))->toBe(['en', 'de']);
});
