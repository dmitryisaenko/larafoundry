<?php

declare(strict_types=1);

it('merges the larafoundry config', function () {
    expect(config('larafoundry.tenancy.mode'))->toBe('teams')
        ->and(config('larafoundry.billing.enabled'))->toBeFalse();
});

it('registers the install command', function () {
    $this->artisan('larafoundry:install')->assertSuccessful();
});
