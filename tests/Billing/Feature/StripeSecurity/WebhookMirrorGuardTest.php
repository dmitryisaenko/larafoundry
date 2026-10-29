<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Listeners\MirrorStripeSubscriptionState;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * The webhook mirror writes access-granting columns from Cashier's WebhookReceived,
 * but Cashier only verifies the Stripe signature when a webhook secret is set.
 * Without one, the mirror must NOT listen — otherwise an unauthenticated request
 * could mutate access. This proves the provider fails closed.
 */
it('does not register the webhook mirror without a signing secret', function () {
    expect(Event::hasListeners(WebhookReceived::class))->toBeFalse();
});

it('does not invoke the mirror for an event when no listener is registered', function () {
    // Dispatching the event is a no-op for our columns: with no listener bound,
    // MirrorStripeSubscriptionState never runs. Guard against a stray binding.
    $listeners = Event::getRawListeners()[WebhookReceived::class] ?? [];

    expect($listeners)->not->toContain(MirrorStripeSubscriptionState::class);
});
