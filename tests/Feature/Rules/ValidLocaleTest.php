<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Rules\ValidLocale;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
});

function validateLocale(mixed $value): bool
{
    return Validator::make(
        ['locale' => $value],
        ['locale' => [new ValidLocale]],
    )->passes();
}

it('accepts a whitelisted locale', function () {
    expect(validateLocale('uk'))->toBeTrue();
    expect(validateLocale('en'))->toBeTrue();
});

it('rejects the legacy non-standard code ua', function () {
    expect(validateLocale('ua'))->toBeFalse();
});

it('rejects a language name used as a code', function () {
    expect(validateLocale('English'))->toBeFalse();
});

it('rejects a locale outside the whitelist', function () {
    expect(validateLocale('de'))->toBeFalse();
});

it('rejects non-string values', function () {
    expect(validateLocale(123))->toBeFalse();
    expect(validateLocale(null))->toBeFalse();
    expect(validateLocale(['en']))->toBeFalse();
});
