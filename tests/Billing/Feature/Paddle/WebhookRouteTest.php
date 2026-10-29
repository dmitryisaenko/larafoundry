<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Paddle\Http\Controllers\WebhookController as PaddleWebhookController;

it('mounts only the Paddle webhook when Paddle is the only gateway', function () {
    $paddle = Route::getRoutes()->getByName('larafoundry-billing.paddle.webhook');

    expect($paddle?->uri())->toBe('paddle/webhook')
        ->and($paddle?->getActionName())->toBe(PaddleWebhookController::class)
        ->and(Route::has('cashier.webhook'))->toBeFalse()
        ->and(Route::has('cashier.payment'))->toBeFalse();
});
