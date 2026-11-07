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

it('lays the english bag under a locale that has not translated everything', function () {
    config()->set('app.fallback_locale', 'en');
    App::setLocale('de');

    $langDir = base_path('lang');
    File::ensureDirectoryExists($langDir);
    File::put($langDir.'/en.json', json_encode(['date_format' => 'Date format', 'Theme' => 'Theme']));
    File::put($langDir.'/de.json', json_encode(['Theme' => 'Design']));

    try {
        $bag = inertiaMiddleware()->exposeTranslations();

        // The locale's own word wins.
        expect($bag['Theme'])->toBe('Design');
        // A host key whose English differs from the key reads in English, not raw.
        expect($bag['date_format'])->toBe('Date format');
        // So does a dotted core key the core ships in English only.
        expect($bag['tickets.category.bug'])->toBe('Bug report');
    } finally {
        File::delete([$langDir.'/en.json', $langDir.'/de.json']);
    }
});

it('lets the core strings of the locale win over the english beneath', function () {
    config()->set('app.fallback_locale', 'en');
    App::setLocale('uk');

    $bag = inertiaMiddleware()->exposeTranslations();

    // The core's own Ukrainian still wins over the English beneath it.
    expect($bag['Employees'])->toBe('Співробітники');
    expect($bag['tickets.category.bug'])->not->toBe('Bug report');
});

it('builds no second bag for the fallback locale itself', function () {
    config()->set('app.fallback_locale', 'en');
    App::setLocale('en');

    $bag = inertiaMiddleware()->exposeTranslations();

    expect($bag['tickets.category.bug'])->toBe('Bug report');
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
