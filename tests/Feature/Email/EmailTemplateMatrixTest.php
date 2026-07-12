<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The template × locale matrix: renders EVERY registered email template in EVERY
 * available locale through the same path production sends (EmailTemplateRepository
 * ::render + sampleData) and asserts each render is healthy. This is the machine
 * half of "test the templates and languages"; the human half is the
 * larafoundry:mail-preview command.
 *
 * Pest prints one line per (code, locale) pair via the dataset, so a failing pair
 * reads like "welcome_email › uk" in the terminal.
 */

/**
 * Build the (code, locale) pairs by reading the package config files directly —
 * the dataset is evaluated before the application boots, so `app()`/`config()`
 * are not available here yet.
 *
 * @return array<string, array{string, string}>
 */
function templateLocalePairs(): array
{
    $base = dirname(__DIR__, 3).'/config';
    $email = require $base.'/larafoundry-email.php';
    $core = require $base.'/larafoundry.php';

    $codes = array_keys($email['templates'] ?? []);
    $locales = $core['locale']['available'] ?? ['en'];

    $pairs = [];
    foreach ($codes as $code) {
        foreach ($locales as $locale) {
            $pairs["{$code} / {$locale}"] = [$code, $locale];
        }
    }

    return $pairs;
}

it('renders every template in every locale with a non-empty subject and body', function (string $code, string $locale) {
    $repository = app(EmailTemplateRepository::class);

    $rendered = $repository->render($code, $locale, $repository->sampleData($code));

    expect($rendered)->not->toBeNull("template [{$code}] is switched off");
    expect(trim($rendered['subject']))->not->toBe('', "empty subject for [{$code}/{$locale}]");
    expect(trim($rendered['html']))->not->toBe('', "empty html body for [{$code}/{$locale}]");
    expect(trim($rendered['text']))->not->toBe('', "empty text body for [{$code}/{$locale}]");
})->with('template_locale_pairs');

it('leaves no unfilled placeholder or stray token in any render', function (string $code, string $locale) {
    $repository = app(EmailTemplateRepository::class);

    $rendered = $repository->render($code, $locale, $repository->sampleData($code));
    $blob = $rendered['subject']."\n".$rendered['html']."\n".$rendered['text'];

    // The renderer is strict {{var}} str_replace; a surviving {{ }} means a token
    // the whitelist/renderer failed to substitute.
    expect($blob)->not->toMatch('/\{\{.*?\}\}/', "stray {{ }} in [{$code}/{$locale}]");

    // sampleData() emits "[name]" only for a whitelisted variable it has no known
    // sample for; a surviving one means that placeholder went unfilled. Scope the
    // check to the template's own variables so a "[DEV]" inside a real value
    // (e.g. app.name) is not a false positive.
    foreach ($repository->variablesFor($code) as $variable) {
        expect($blob)->not->toContain("[{$variable}]", "unfilled [{$variable}] in [{$code}/{$locale}]");
    }
})->with('template_locale_pairs');

it('has a real translation for every non-default locale (not just the default fallback)', function (string $code, string $locale) {
    $repository = app(EmailTemplateRepository::class);

    if ($locale === $repository->defaultLocale()) {
        expect(true)->toBeTrue();

        return;
    }

    $definition = $repository->find($code);

    // The subject map must carry this locale explicitly — otherwise the render
    // silently falls back to the default locale and the email ships untranslated.
    expect(array_key_exists($locale, $definition['subject']))
        ->toBeTrue("no [{$locale}] subject for template [{$code}] — it would ship in the default locale");
    expect(trim((string) $definition['subject'][$locale]))
        ->not->toBe('', "blank [{$locale}] subject for template [{$code}]");
})->with('template_locale_pairs');

dataset('template_locale_pairs', templateLocalePairs());
