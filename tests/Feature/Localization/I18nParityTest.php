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

function langDir(): string
{
    return dirname(__DIR__, 3).'/lang';
}

/**
 * Flatten one locale's every group file into dot-keyed leaves: "auth.welcome.subject".
 *
 * @return array<string, mixed>
 */
function flattenLocaleKeys(string $locale): array
{
    $dir = langDir().'/'.$locale;
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
 * Available non-default locales discovered from the lang directory.
 *
 * @return list<string>
 */
function nonDefaultLocales(string $default = 'en'): array
{
    $locales = [];
    foreach (glob(langDir().'/*', GLOB_ONLYDIR) ?: [] as $path) {
        $name = basename($path);
        // Skip the frontend JSON bundle dir and the default locale.
        if ($name === 'frontend' || $name === $default) {
            continue;
        }
        $locales[] = $name;
    }

    return $locales;
}

it('has the same set of translation keys in every locale as the default (en)', function (string $locale) {
    $en = array_keys(flattenLocaleKeys('en'));
    $other = array_keys(flattenLocaleKeys($locale));

    $missingInOther = array_values(array_diff($en, $other));
    $orphanInOther = array_values(array_diff($other, $en));

    expect($missingInOther)->toBe([], "keys present in en but MISSING in [{$locale}]:\n  ".implode("\n  ", $missingInOther));
    expect($orphanInOther)->toBe([], "keys present in [{$locale}] but not in en (orphans):\n  ".implode("\n  ", $orphanInOther));
})->with(nonDefaultLocales());

it('has no blank translation values in any locale', function (string $locale) {
    $blank = [];
    foreach (flattenLocaleKeys($locale) as $key => $value) {
        if (is_string($value) && trim($value) === '') {
            $blank[] = $key;
        }
    }

    expect($blank)->toBe([], "blank values in [{$locale}]:\n  ".implode("\n  ", $blank));
})->with([...nonDefaultLocales(), 'en']);
