<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;

beforeEach(function () {
    config()->set('larafoundry.profile.ui_settings', [
        'theme' => [
            'type' => 'string',
            'default' => 'system',
            'in' => ['light', 'dark', 'system'],
            'label' => 'Theme',
            'labels' => ['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'],
        ],
        'sidebar_collapsed' => ['type' => 'boolean', 'default' => false, 'label' => 'Collapse sidebar'],
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

it('exposes a frontend schema with options and human labels', function () {
    $schema = collect(UiSettings::schema())->keyBy('key');

    expect($schema['theme']['type'])->toBe('string')
        ->and($schema['theme']['label'])->toBe('Theme')
        ->and($schema['theme']['options'])->toBe(['light', 'dark', 'system'])
        ->and($schema['theme']['option_labels'])->toBe(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'])
        ->and($schema['sidebar_collapsed']['label'])->toBe('Collapse sidebar')
        ->and($schema['sidebar_collapsed']['options'])->toBe([])
        ->and($schema['sidebar_collapsed']['option_labels'])->toBe([]);
});

it('falls back to the raw key as label when none is declared', function () {
    config()->set('larafoundry.profile.ui_settings', [
        'x_setting' => ['type' => 'string'],
    ]);

    $schema = collect(UiSettings::schema())->keyBy('key');

    expect($schema['x_setting']['label'])->toBe('x_setting')
        ->and($schema['x_setting']['option_labels'])->toBe([]);
});
