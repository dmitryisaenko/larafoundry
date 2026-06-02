<?php

declare(strict_types=1);

it('ships sane auth defaults', function () {
    expect(config('larafoundry.auth.password_min_length'))->toBe(8)
        ->and(config('larafoundry.auth.oauth.enabled'))->toBeFalse()
        ->and(config('larafoundry.auth.oauth.link_existing'))->toBeFalse()
        ->and(config('larafoundry.auth.two_factor.confirm'))->toBeTrue();
});
