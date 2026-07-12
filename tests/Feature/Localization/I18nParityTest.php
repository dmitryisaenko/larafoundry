<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Translation parity across the core's shipped locales: every dot-key present in
 * the default locale (en) must exist in each other available locale, and vice
 * versa. Catches the classic drift where a new string is added to en but the uk
 * file is forgotten (or an orphan uk key lingers after an en key is removed).
 *
 * Reads the package's own lang/<locale>/*.php files directly — the same files the
 * `larafoundry::` namespace loads — so it needs no app boot.
 */

function i18nLangDir(): string
{
    return dirname(__DIR__, 3).'/lang';
}

/**
 * Flatten one locale's every group file into dot-keyed leaves: "auth.welcome.subject".
 *
 * @return array<string, mixed>
 */
function i18nFlattenLocaleKeys(string $locale): array
{
    $dir = i18nLangDir().'/'.$locale;
    $keys = [];

    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $group = basename($file, '.php');
        $translations = require $file;

        if (! is_array($translations)) {
            continue;
        }

        foreach (Arr::dot($translations) as $key => $value) {
            $keys[$group.'.'.$key] = $value;
        }
    }

    return $keys;
}

/**
 * The CONFIGURED non-default locales (config allowlist minus the default), read
 * from the package config file directly — the dataset is built before the app
 * boots, so config() is not available here. Keying off the config (not the lang
 * dirs) means a locale that is declared available but has no lang/<locale> files
 * fails loudly with a full set of missing keys, instead of being silently
 * skipped.
 *
 * @return list<string>
 */
function i18nNonDefaultLocales(): array
{
    $core = require dirname(__DIR__, 3).'/config/larafoundry.php';
    $available = $core['locale']['available'] ?? ['en'];
    $default = $core['locale']['default'] ?? 'en';

    return array_values(array_filter($available, fn ($locale) => $locale !== $default));
}

it('has the same set of translation keys in every locale as the default (en)', function (string $locale) {
    $en = array_keys(i18nFlattenLocaleKeys('en'));
    $other = array_keys(i18nFlattenLocaleKeys($locale));

    $missingInOther = array_values(array_diff($en, $other));
    $orphanInOther = array_values(array_diff($other, $en));

    expect($missingInOther)->toBe([], "keys present in en but MISSING in [{$locale}]:\n  ".implode("\n  ", $missingInOther));
    expect($orphanInOther)->toBe([], "keys present in [{$locale}] but not in en (orphans):\n  ".implode("\n  ", $orphanInOther));
})->with(i18nNonDefaultLocales());

it('has no blank translation values in any locale', function (string $locale) {
    $blank = [];
    foreach (i18nFlattenLocaleKeys($locale) as $key => $value) {
        if (is_string($value) && trim($value) === '') {
            $blank[] = $key;
        }
    }

    expect($blank)->toBe([], "blank values in [{$locale}]:\n  ".implode("\n  ", $blank));
})->with([...i18nNonDefaultLocales(), 'en']);
