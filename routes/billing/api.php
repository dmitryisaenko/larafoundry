<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\ValidatePromoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry Billing add-on routes (sub-phase B5)
|--------------------------------------------------------------------------
| Loaded by the service provider ONLY when
| `larafoundry-billing.promo.validate_route.enabled` is true, so a host that does
| not surface a public promo-check endpoint ships none. The prefix, middleware and
| throttle all come from config so the host tunes them without editing the package.
|
| The named `larafoundry-billing-promo` rate limiter (registered in the provider)
| is what makes the endpoint safe to expose: the donor's equivalent had no throttle
| and an unthrottled validate endpoint is a code-enumeration oracle.
*/

$config = config('larafoundry-billing.promo.validate_route', []);

$prefix = is_array($config) && is_string($config['prefix'] ?? null)
    ? $config['prefix']
    : 'larafoundry-billing';

$middleware = is_array($config) && is_array($config['middleware'] ?? null)
    ? $config['middleware']
    : ['api'];

// Always append the named limiter so a host that overrode `middleware` cannot
// accidentally drop the throttle that protects this endpoint.
$middleware[] = 'throttle:larafoundry-billing-promo';

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function () {
        Route::post('promo/validate', ValidatePromoController::class)
            ->name('larafoundry-billing.promo.validate');
    });
