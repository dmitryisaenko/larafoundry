<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController as StripeWebhookController;

it('mounts only the Stripe webhook when Stripe is the only gateway', function () {
    $stripe = Route::getRoutes()->getByName('cashier.webhook');

    expect($stripe?->uri())->toBe('stripe/webhook')
        ->and($stripe?->getActionName())->toBe(StripeWebhookController::class.'@handleWebhook')
        ->and(Route::has('larafoundry-billing.paddle.webhook'))->toBeFalse();
});
