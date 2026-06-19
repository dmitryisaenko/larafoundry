<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;

/**
 * Guards that the company `timezone` setting offers a dropdown (options) rather
 * than a bare text input — driven by the registry's `in` list, surfaced through
 * schemaForScope. Uses the real package config (no override), so removing `in`
 * from config/larafoundry.php would fail this test.
 */
it('offers the IANA timezone list as company timezone options', function () {
    $schema = collect((new SettingsRepository)->schemaForScope('company'))->keyBy('key');

    expect($schema)->toHaveKey('timezone')
        ->and($schema['timezone']['type'])->toBe('string')
        ->and($schema['timezone']['options'])->toContain('UTC')
        ->and($schema['timezone']['options'])->toContain('Europe/Kyiv')
        ->and(count($schema['timezone']['options']))->toBeGreaterThan(100);
});
