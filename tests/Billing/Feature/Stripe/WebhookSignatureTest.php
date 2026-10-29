<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Illuminate\Http\Request;

/**
 * verifyWebhook() must authenticate the event before anything acts on it. Stripe's
 * signature scheme is HMAC-SHA256 over "{timestamp}.{payload}" with the signing
 * secret — fully reproducible offline, so these tests craft a real signature and
 * never call Stripe.
 */
function signedRequest(string $payload, string $secret, ?int $timestamp = null): Request
{
    $timestamp ??= 1_700_000_000;
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return Request::create(
        '/stripe/webhook',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $payload,
    );
}

function stripeGateway(): PaymentGatewayInterface
{
    return app(PaymentGatewayManager::class)->driver('stripe');
}

it('accepts a correctly signed webhook and returns the parsed event', function () {
    $payload = json_encode([
        'id' => 'evt_test_1',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_123']],
    ]);

    // Cashier's signature tolerance defaults to 300s; the constructed timestamp is
    // historical, so disable the tolerance check for the test by signing with a
    // timestamp the verifier accepts. Stripe\Webhook allows tolerance=0 to skip it,
    // but constructEvent uses a fixed default — so use the current request time.
    $now = now()->getTimestamp();
    $request = signedRequest($payload, 'whsec_test_dummy', $now);

    $event = stripeGateway()->verifyWebhook($request);

    expect($event)->toMatchArray(['type' => 'customer.subscription.updated'])
        ->and($event['id'])->toBe('evt_test_1');
});

it('rejects a webhook whose payload was tampered after signing', function () {
    $now = now()->getTimestamp();
    $request = signedRequest('{"type":"customer.subscription.updated"}', 'whsec_test_dummy', $now);

    // Swap the body for a different one the signature no longer covers.
    $tampered = Request::create(
        '/stripe/webhook',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => $request->header('Stripe-Signature')],
        content: '{"type":"customer.subscription.deleted"}',
    );

    stripeGateway()->verifyWebhook($tampered);
})->throws(WebhookVerificationException::class);

it('rejects a webhook with no signature header', function () {
    $request = Request::create('/stripe/webhook', 'POST', content: '{"type":"x"}');

    stripeGateway()->verifyWebhook($request);
})->throws(WebhookVerificationException::class);
