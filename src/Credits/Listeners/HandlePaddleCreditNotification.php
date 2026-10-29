<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Listeners;

use Dmitryisaenko\LaraFoundry\Credits\Gateways\PaddleCreditPurchases;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Feeds the core's `/paddle/webhook` route into credit purchases (v0.47.0).
 *
 * Attached only with billing on, the Paddle gateway configured and a webhook
 * secret set - the same rule as the subscription mirror: Cashier-Paddle verifies
 * the signature on its route only when a secret is set, and this listener moves
 * money. A failure is left to propagate, so Paddle retries the notification.
 */
class HandlePaddleCreditNotification
{
    public function __construct(private readonly PaddleCreditPurchases $paddle) {}

    public function handle(WebhookReceived $event): void
    {
        $this->paddle->handle($event->payload);
    }
}
