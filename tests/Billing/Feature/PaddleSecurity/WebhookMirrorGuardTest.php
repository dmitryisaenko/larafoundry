<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Listeners\MirrorPaddleSubscriptionState;
use Illuminate\Support\Facades\Event;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * The Paddle webhook mirror writes access-granting columns from Cashier-Paddle's
 * WebhookReceived, but Cashier-Paddle only verifies the signature when a webhook
 * secret is set. Without one, the mirror must NOT listen — otherwise an
 * unauthenticated request could mutate access. This proves the provider fails closed.
 */
it('does not register the paddle webhook mirror without a signing secret', function () {
    expect(Event::hasListeners(WebhookReceived::class))->toBeFalse();
});

it('does not bind the paddle mirror listener when no signing secret is set', function () {
    $listeners = Event::getRawListeners()[WebhookReceived::class] ?? [];

    expect($listeners)->not->toContain(MirrorPaddleSubscriptionState::class);
});
