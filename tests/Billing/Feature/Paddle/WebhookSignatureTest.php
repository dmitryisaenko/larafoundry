<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Illuminate\Http\Request;

/**
 * verifyWebhook() must authenticate the event before anything acts on it. Paddle's
 * scheme is HMAC-SHA256 over "{ts}:{rawBody}" with the signing secret, carried in
 * the `Paddle-Signature` header as `ts=<unix>;h1=<hex>` — fully reproducible
 * offline, so these tests craft a real signature and never call Paddle.
 */
function paddleSignedRequest(string $payload, string $secret, ?int $timestamp = null): Request
{
    // Sign with the current time so the driver's freshness tolerance (300s) accepts it.
    $timestamp ??= now()->getTimestamp();
    $h1 = hash_hmac('sha256', "{$timestamp}:{$payload}", $secret);

    return Request::create(
        '/paddle/webhook',
        'POST',
        server: ['HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$h1}"],
        content: $payload,
    );
}

function paddleGateway(): PaymentGatewayInterface
{
    return app(PaymentGatewayManager::class)->driver('paddle');
}

it('accepts a correctly signed webhook and returns the parsed event', function () {
    $payload = json_encode([
        'event_id' => 'evt_test_1',
        'event_type' => 'subscription.updated',
        'data' => ['id' => 'sub_123', 'status' => 'active'],
    ]);

    $request = paddleSignedRequest($payload, 'pdl_whsec_test_dummy');

    $event = paddleGateway()->verifyWebhook($request);

    expect($event)->toMatchArray(['event_type' => 'subscription.updated'])
        ->and($event['event_id'])->toBe('evt_test_1');
});

it('rejects a webhook whose payload was tampered after signing', function () {
    $request = paddleSignedRequest('{"event_type":"subscription.updated"}', 'pdl_whsec_test_dummy');

    // Swap the body for a different one the signature no longer covers.
    $tampered = Request::create(
        '/paddle/webhook',
        'POST',
        server: ['HTTP_PADDLE_SIGNATURE' => $request->header('Paddle-Signature')],
        content: '{"event_type":"subscription.canceled"}',
    );

    paddleGateway()->verifyWebhook($tampered);
})->throws(WebhookVerificationException::class);

it('rejects a webhook signed with the wrong secret', function () {
    $payload = '{"event_type":"subscription.updated"}';
    $request = paddleSignedRequest($payload, 'pdl_whsec_WRONG');

    paddleGateway()->verifyWebhook($request);
})->throws(WebhookVerificationException::class);

it('rejects a webhook with no signature header', function () {
    $request = Request::create('/paddle/webhook', 'POST', content: '{"event_type":"x"}');

    paddleGateway()->verifyWebhook($request);
})->throws(WebhookVerificationException::class);

it('rejects a webhook whose timestamp is outside the tolerance window', function () {
    // A signature from well beyond the 300s tolerance must be refused even though
    // the hash itself is correct — replay protection.
    $payload = '{"event_type":"subscription.updated"}';
    $stale = now()->getTimestamp() - 3600;
    $request = paddleSignedRequest($payload, 'pdl_whsec_test_dummy', $stale);

    paddleGateway()->verifyWebhook($request);
})->throws(WebhookVerificationException::class);
