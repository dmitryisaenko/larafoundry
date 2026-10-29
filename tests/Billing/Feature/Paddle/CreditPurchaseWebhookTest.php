<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Credits\Gateways\PaddleCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Listeners\HandlePaddleCreditNotification;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Paddle\Events\WebhookReceived;

uses(RefreshDatabase::class);

/*
| With billing on and the Paddle gateway configured, the core's own
| `/paddle/webhook` route feeds credit purchases (v0.47.0).
*/

it('attaches the credit purchases to the verified Paddle webhook', function () {
    expect(Event::hasListeners(WebhookReceived::class))->toBeTrue()
        ->and(collect(Event::getRawListeners()[WebhookReceived::class] ?? [])->contains(HandlePaddleCreditNotification::class))->toBeTrue();
});

it('credits an opened purchase from a signed notification on the core route', function () {
    $user = PaymentUser::create(['name' => 'Buyer', 'email' => 'buyer@x.test', 'password' => 'secret-pass']);
    app(CreditPurchaseService::class)->open($user, 5000, 969, 'USD', PaddleCreditPurchases::GATEWAY, 'txn_route');

    $body = json_encode([
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => 'txn_route',
            'status' => 'completed',
            'customer_id' => 'ctm_unknown',
            'currency_code' => 'USD',
            'custom_data' => ['kind' => PaddleCreditPurchases::KIND],
            'details' => ['totals' => ['grand_total' => '969', 'total' => '969', 'tax' => '0']],
        ],
    ]);
    $ts = now()->getTimestamp();
    $signature = "ts={$ts};h1=".hash_hmac('sha256', "{$ts}:{$body}", 'pdl_whsec_test_dummy');

    $this->call('POST', '/paddle/webhook', [], [], [], [
        'HTTP_PADDLE_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(app(CreditService::class)->balance($user))->toBe(5000);
});

it('acts on nothing that is unsigned', function () {
    $user = PaymentUser::create(['name' => 'Buyer', 'email' => 'buyer@x.test', 'password' => 'secret-pass']);
    app(CreditPurchaseService::class)->open($user, 5000, 969, 'USD', PaddleCreditPurchases::GATEWAY, 'txn_route');

    $this->postJson('/paddle/webhook', [
        'event_type' => 'transaction.completed',
        'data' => ['id' => 'txn_route', 'status' => 'completed', 'currency_code' => 'USD',
            'custom_data' => ['kind' => PaddleCreditPurchases::KIND],
            'details' => ['totals' => ['grand_total' => '969']]],
    ])->assertForbidden();

    expect(app(CreditService::class)->balance($user))->toBe(0);
});
