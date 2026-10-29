<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * The billing console sits in the operator console, so by default it is behind the
 * same super-admin OTP step-up as every other console screen. Without it a stolen
 * super-admin session that never passed OTP reached payer data, promo codes and
 * payouts.
 */
it('puts the billing and affiliate consoles behind the admin OTP step-up', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('larafoundry.admin', 'larafoundry.admin.otp');
})->with([
    'admin.payments.index',
    'admin.promo-codes.index',
    'admin.affiliates.index',
    'admin.payouts.index',
]);
