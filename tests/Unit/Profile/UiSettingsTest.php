<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;

beforeEach(function () {
    config()->set('larafoundry.profile.ui_settings', [
        'theme' => ['type' => 'string', 'default' => 'system', 'in' => ['light', 'dark', 'system']],
        'sidebar_collapsed' => ['type' => 'boolean', 'default' => false],
    ]);
});

it('returns declared defaults for keys that are not stored', function () {
    $user = (object) ['ui_settings' => []];

    expect(UiSettings::resolved($user))->toBe([
        'theme' => 'system',
        'sidebar_collapsed' => false,
    ]);
});

it('casts stored values to their declared type on read', function () {
    $user = (object) ['ui_settings' => ['sidebar_collapsed' => '1', 'theme' => 'dark']];

    $resolved = UiSettings::resolved($user);

    expect($resolved['sidebar_collapsed'])->toBeTrue()
        ->and($resolved['theme'])->toBe('dark');
});

it('drops non-allowlisted keys lingering in the column', function () {
    $user = (object) ['ui_settings' => ['evil' => 'x', 'theme' => 'light']];

    $resolved = UiSettings::resolved($user);

    expect($resolved)->not->toHaveKey('evil')
        ->and($resolved['theme'])->toBe('light');
});

it('reports allowlist membership', function () {
    expect(UiSettings::isAllowed('theme'))->toBeTrue()
        ->and(UiSettings::isAllowed('is_admin'))->toBeFalse();
});

it('exposes a frontend schema with options', function () {
    $schema = collect(UiSettings::schema())->keyBy('key');

    expect($schema['theme']['type'])->toBe('string')
        ->and($schema['theme']['options'])->toBe(['light', 'dark', 'system'])
        ->and($schema['sidebar_collapsed']['options'])->toBe([]);
});
