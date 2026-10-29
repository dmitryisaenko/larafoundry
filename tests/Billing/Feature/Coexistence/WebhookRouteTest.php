<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController as StripeWebhookController;
use Laravel\Paddle\Http\Controllers\WebhookController as PaddleWebhookController;

/**
 * Both Cashier packages read the shared `cashier.path`, which Cashier-Stripe sets to
 * `stripe`. Left to itself Cashier-Paddle mounted its controller on `/stripe/webhook`
 * too and took the route over, so every Stripe event hit the Paddle controller. Each
 * gateway must keep its own path and its own controller.
 */
it('routes each gateway webhook to its own Cashier controller', function () {
    $actions = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->mapWithKeys(fn ($route) => [$route->uri() => $route->getActionName()]);

    expect($actions->get('stripe/webhook'))->toBe(StripeWebhookController::class.'@handleWebhook')
        ->and($actions->get('paddle/webhook'))->toBe(PaddleWebhookController::class);
});

it('verifies the signature on both webhook routes', function () {
    $this->postJson('/stripe/webhook', ['type' => 'invoice.paid'])->assertForbidden();
    $this->postJson('/paddle/webhook', ['event_type' => 'transaction.completed'])->assertForbidden();
});
