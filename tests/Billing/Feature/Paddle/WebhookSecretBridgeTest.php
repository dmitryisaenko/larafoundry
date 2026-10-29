<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Listeners\MirrorPaddleSubscriptionState;
use Illuminate\Support\Facades\Event;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Closes the fail-open gap found in review: Cashier-Paddle's webhook route attaches
 * its signature-verification middleware ONLY when the FLAT `cashier.webhook_secret`
 * is set. A host that configured just the add-on key would otherwise get an
 * unverified route with the access-mutating mirror live. The provider bridges the
 * add-on key into `cashier.webhook_secret` so the route's verification and the
 * mirror's guard read one source of truth.
 *
 * PaddleTestCase sets both keys; here we assert the bridge made them equal and that
 * the mirror is armed off the (now-bridged) Cashier key.
 */
it('bridges the add-on webhook secret into the cashier key the route verifies on', function () {
    expect(config('cashier.webhook_secret'))->toBe(config('larafoundry-billing.paddle.webhook.secret'))
        ->and(config('cashier.webhook_secret'))->not->toBeEmpty();
});

it('arms the paddle mirror when a webhook secret is present', function () {
    expect(Event::hasListeners(WebhookReceived::class))->toBeTrue();

    $listeners = Event::getRawListeners()[WebhookReceived::class] ?? [];

    expect($listeners)->toContain(MirrorPaddleSubscriptionState::class);
});
