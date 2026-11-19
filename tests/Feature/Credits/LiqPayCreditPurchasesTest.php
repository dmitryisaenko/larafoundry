<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\SandboxPaymentNotAllowedException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\CreditPurchaseUnavailableException;
use Dmitryisaenko\LaraFoundry\Credits\Gateways\LiqPayCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| Billing stays OFF here (the AuthTestCase default): LiqPay purchases work without
| the billing switch, like the Paddle ones.
*/

const LP_PRIVATE = 'liqpay_private_test_key';

beforeEach(function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    config()->set('larafoundry.credits.purchases.liqpay', [
        'public_key' => 'i00000001',
        'private_key' => LP_PRIVATE,
        'rro_product_id' => null,
    ]);
    // The main list (Paddle's): dollars.
    config()->set('larafoundry.credits.purchases.currency', 'USD');
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 969]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2]);
    // Hryvnias: round prices of their own, no custom amounts.
    config()->set('larafoundry.credits.purchases.currencies', [
        'UAH' => ['bundles' => [['credits' => 5000, 'price_cents' => 39900]], 'custom' => ['min' => 2500, 'max' => 0]],
    ]);
    config()->set('larafoundry.credits.purchases.routes', [
        'default' => ['gateway' => 'paddle'],
        'countries' => ['UA' => ['gateway' => 'liqpay', 'currency' => 'UAH']],
    ]);
    config()->set('larafoundry-billing.install.name', null);
});

function lpUser(string $email = 'buyer@x.test'): User
{
    return User::create([
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function lpGateway(): LiqPayCreditPurchases
{
    return app(LiqPayCreditPurchases::class);
}

function lpBalance(User $user): int
{
    return app(CreditService::class)->balance($user);
}

/** @return array<string, mixed> */
function lpParams(string $data): array
{
    return json_decode(base64_decode($data), true);
}

/**
 * A notification as LiqPay posts it, signed with `$algorithm`.
 *
 * @param  array<string, mixed>  $payment
 */
function lpNotification(array $payment, string $algorithm = 'sha3-256', string $key = LP_PRIVATE): Request
{
    $data = base64_encode(json_encode($payment));
    $signature = base64_encode(hash($algorithm, $key.$data.$key, true));

    return Request::create('/liqpay/notify', 'POST', ['data' => $data, 'signature' => $signature]);
}

/** @return array<string, mixed> */
function lpPaid(string $orderId, string $amount = '399', string $currency = 'UAH', string $status = 'success'): array
{
    return [
        'public_key' => 'i00000001',
        'order_id' => $orderId,
        'status' => $status,
        'amount' => $amount,
        'currency' => $currency,
        'sender_card_country' => '804',
        'sender_card_bank' => 'pb',
        'paytype' => 'card',
    ];
}

function lpAllowTesters(bool $allow): void
{
    app()->bind(SandboxTesters::class, fn () => new class($allow) implements SandboxTesters
    {
        public function __construct(private bool $allow) {}

        public function allows(?Authenticatable $user): bool
        {
            return $this->allow;
        }
    });
}

it('signs exactly as LiqPay documents it (sha3-256 of key + data + key)', function () {
    config()->set('larafoundry.credits.purchases.liqpay.private_key', 'a4825234f4bae72a0be04eafe9e8e2bada209255');
    $data = 'eyJwdWJsaWNfa2V5IjoiaTAwMDAwMDAwIiwidmVyc2lvbiI6NywiYWN0aW9uIjoicGF5IiwiYW1vdW50IjoiMyIsImN1cnJlbmN5IjoiVUFIIiwiZGVzY3JpcHRpb24iOiJ0ZXN0Iiwib3JkZXJfaWQiOiIwMDAwMDEifQ==';

    // The worked example on liqpay.ua/doc/api/internet_acquiring/checkout.
    expect(lpGateway()->sign($data))->toBe('0adgJ8F2Ds5HCVkcz4AlmdLMRoIJf7IxsL3QmeFRz/s=');
});

it('opens a checkout at the server price in the route currency and records it pending', function () {
    $user = lpUser();

    $checkout = lpGateway()->start($user, 5000, 'UA', ['product' => 'lorevid'],
        resultUrl: 'https://my.example.test/billing', serverUrl: 'https://my.example.test/liqpay/notify', language: 'uk');

    expect($checkout)->toMatchArray([
        'url' => LiqPayCreditPurchases::CHECKOUT_URL,
        'credits' => 5000,
        'bonus_credits' => 0,
        'price_cents' => 39900,
        'currency' => 'UAH',
        'sandbox' => false,
    ])->and($checkout['signature'])->toBe(lpGateway()->sign($checkout['data']));

    expect(lpParams($checkout['data']))->toMatchArray([
        'version' => 7,
        'public_key' => 'i00000001',
        'action' => 'pay',
        'amount' => '399.00',
        'currency' => 'UAH',
        'order_id' => $checkout['order_id'],
        'language' => 'uk',
        'result_url' => 'https://my.example.test/billing',
        'server_url' => 'https://my.example.test/liqpay/notify',
    ])->and(lpParams($checkout['data']))->not->toHaveKey('rro_info');

    $purchase = CreditPurchase::query()->sole();

    expect($purchase->status)->toBe(CreditPurchase::STATUS_PENDING)
        ->and($purchase->gateway)->toBe('liqpay')
        ->and($purchase->reference)->toBe($checkout['order_id'])
        ->and($purchase->credits)->toBe(5000)
        ->and($purchase->amount_cents)->toBe(39900)
        ->and($purchase->currency)->toBe('UAH')
        ->and($purchase->sandbox)->toBeFalse()
        ->and($purchase->meta)->toBe(['product' => 'lorevid', 'liqpay' => ['country' => 'UA', 'pack' => 5000]]);

    expect(lpBalance($user))->toBe(0);
});

it('puts the installation name into the order id and never repeats one', function () {
    config()->set('larafoundry-billing.install.name', 'Lorevid');
    $user = lpUser();

    $first = lpGateway()->start($user, 5000, 'UA')['order_id'];
    $second = lpGateway()->start($user, 5000, 'UA')['order_id'];

    expect($first)->toStartWith('lf-lorevid-')
        ->and($second)->toStartWith('lf-lorevid-')
        ->and($first)->not->toBe($second);
});

it('refuses a buyer whose country pays through another gateway, and an unknown country', function (?string $country) {
    expect(fn () => lpGateway()->start(lpUser(), 5000, $country))
        ->toThrow(CreditPurchaseUnavailableException::class);

    expect(CreditPurchase::query()->count())->toBe(0);
})->with([['DE'], [null], ['XX']]);

it('adds the route bonus to the pack and charges the pack price', function () {
    config()->set('larafoundry.credits.purchases.routes.countries.US', ['gateway' => 'liqpay', 'bonus_percent' => 5]);

    $checkout = lpGateway()->start(lpUser(), 5000, 'US');

    // Dollars from the main list, 5% more credits than the pack.
    expect($checkout)->toMatchArray(['credits' => 5250, 'bonus_credits' => 250, 'price_cents' => 969, 'currency' => 'USD'])
        ->and(lpParams($checkout['data'])['amount'])->toBe('9.69');

    expect(CreditPurchase::query()->sole())
        ->credits->toBe(5250)
        ->meta->toBe(['liqpay' => ['country' => 'US', 'pack' => 5000, 'bonus_percent' => 5]]);
});

it('refuses an amount not on sale in the route currency', function () {
    // 7000 is a custom amount: on sale in dollars, not in hryvnias (no per-credit price).
    expect(fn () => lpGateway()->start(lpUser(), 7000, 'UA'))
        ->toThrow(CreditPurchaseUnavailableException::class);

    config()->set('larafoundry.credits.purchases.currencies', []);

    expect(fn () => lpGateway()->start(lpUser('b@x.test'), 5000, 'UA'))
        ->toThrow(CreditPurchaseUnavailableException::class);
});

it('refuses to open a checkout without keys', function () {
    config()->set('larafoundry.credits.purchases.liqpay.private_key', '');

    expect(fn () => lpGateway()->start(lpUser(), 5000, 'UA'))->toThrow(GatewayNotConfiguredException::class);
    expect(CreditPurchase::query()->count())->toBe(0);
});

it('fails loudly when the two keys come from different pairs', function (string $public, string $private) {
    config()->set('larafoundry.credits.purchases.liqpay.public_key', $public);
    config()->set('larafoundry.credits.purchases.liqpay.private_key', $private);
    lpAllowTesters(true);

    expect(fn () => lpGateway()->start(lpUser(), 5000, 'UA'))->toThrow(GatewayNotConfiguredException::class);
    expect(CreditPurchase::query()->count())->toBe(0);
})->with([
    'test public, live private' => ['sandbox_i000001', 'live_private'],
    'live public, test private' => ['i000001', 'sandbox_private'],
]);

it('lets only testers pay on test keys and marks their purchases', function () {
    config()->set('larafoundry.credits.purchases.liqpay.public_key', 'sandbox_i000001');
    config()->set('larafoundry.credits.purchases.liqpay.private_key', 'sandbox_private');

    expect(fn () => lpGateway()->start(lpUser(), 5000, 'UA'))->toThrow(SandboxPaymentNotAllowedException::class);
    expect(CreditPurchase::query()->count())->toBe(0);

    lpAllowTesters(true);
    $checkout = lpGateway()->start(lpUser('t@x.test'), 5000, 'UA');

    expect($checkout['sandbox'])->toBeTrue()
        ->and(CreditPurchase::query()->sole()->sandbox)->toBeTrue();
});

it('sends the receipt data only with a product registered in the LiqPay cabinet', function () {
    config()->set('larafoundry.credits.purchases.liqpay.rro_product_id', '123456');

    $checkout = lpGateway()->start(lpUser(), 5000, 'UA', receiptEmail: 'buyer@x.test');

    expect(lpParams($checkout['data'])['rro_info'])->toBe([
        'items' => [['amount' => 1, 'price' => 399, 'cost' => 399, 'id' => 123456]],
        'delivery_emails' => ['buyer@x.test'],
    ]);
});

it('credits a paid notification once and records the card country beside the connection country', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    $purchase = lpGateway()->handleRequest(lpNotification(lpPaid($orderId)));

    expect($purchase?->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(lpBalance($user))->toBe(5000)
        ->and($purchase->fresh()->meta)->toBe(['liqpay' => [
            'country' => 'UA', 'pack' => 5000, 'card_country' => '804', 'card_bank' => 'pb', 'paytype' => 'card',
        ]]);

    // LiqPay sends it again: nothing moves.
    lpGateway()->handleRequest(lpNotification(lpPaid($orderId)));

    expect(lpBalance($user))->toBe(5000);
});

it('credits a payment settled in the daily payout', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    lpGateway()->handleRequest(lpNotification(lpPaid($orderId, status: 'wait_compensation')));

    expect(lpBalance($user))->toBe(5000);
});

it('accepts a notification signed the older way (sha1)', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    lpGateway()->handleRequest(lpNotification(lpPaid($orderId), 'sha1'));

    expect(lpBalance($user))->toBe(5000);
});

it('rejects a notification not signed with our private key', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(fn () => lpGateway()->handleRequest(lpNotification(lpPaid($orderId), key: 'somebody_else')))
        ->toThrow(WebhookVerificationException::class);

    expect(fn () => lpGateway()->handleRequest(Request::create('/liqpay/notify', 'POST', ['data' => 'x'])))
        ->toThrow(WebhookVerificationException::class);

    expect(lpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
});

it('credits nothing when less was paid or in another currency', function (string $amount, string $currency) {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(lpGateway()->handleRequest(lpNotification(lpPaid($orderId, $amount, $currency))))->toBeNull();

    expect(lpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
})->with([
    'less' => ['398.99', 'UAH'],
    'dollars' => ['399', 'USD'],
]);

it('credits nothing for a payment that is not the shop\'s yet or failed', function (string $status) {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(lpGateway()->handleRequest(lpNotification(lpPaid($orderId, status: $status))))->toBeNull();

    expect(lpBalance($user))->toBe(0);
})->with(['wait_accept', 'failure', 'error', 'processing', 'sandbox']);

it('leaves alone an order this installation has no row for, and another shop\'s payment', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(lpGateway()->handle(lpPaid('lf-other-site-01')))->toBeNull()
        ->and(lpGateway()->handle(['public_key' => 'i99999999'] + lpPaid($orderId)))->toBeNull();

    expect(lpBalance($user))->toBe(0);
});

it('takes the credits back on a full refund, never below zero', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];
    lpGateway()->handle(lpPaid($orderId));
    app(CreditService::class)->debit($user, 1000, 'spent');

    $purchase = lpGateway()->handleRequest(lpNotification(lpPaid($orderId, status: 'reversed') + ['refund_amount' => 399]));

    expect($purchase?->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and($purchase->credits_reversed)->toBe(4000)
        ->and(lpBalance($user))->toBe(0);
});

it('leaves a partial refund to a person', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];
    lpGateway()->handle(lpPaid($orderId));

    expect(lpGateway()->handle(lpPaid($orderId, status: 'reversed') + ['refund_amount' => 100]))->toBeNull();

    expect(lpBalance($user))->toBe(5000)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_COMPLETED);
});

it('asks LiqPay about a pending purchase and credits it when paid', function () {
    $user = lpUser();
    $orderId = lpGateway()->start($user, 5000, 'UA')['order_id'];

    Http::fake([LiqPayCreditPurchases::API_URL => Http::response(['result' => 'ok'] + lpPaid('ignored-here', '399.00'))]);

    $purchase = lpGateway()->sync(CreditPurchase::query()->sole());

    expect($purchase?->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(lpBalance($user))->toBe(5000);

    Http::assertSent(function ($request) use ($orderId) {
        $params = lpParams($request['data']);

        return $request->url() === LiqPayCreditPurchases::API_URL
            && $params['action'] === 'status'
            && $params['order_id'] === $orderId
            && $request['signature'] === lpGateway()->sign($request['data']);
    });
});

it('leaves a purchase pending when LiqPay knows no such payment or cannot be reached', function (int $code, array $body) {
    $user = lpUser();
    lpGateway()->start($user, 5000, 'UA');

    Http::fake([LiqPayCreditPurchases::API_URL => Http::response($body, $code)]);

    expect(lpGateway()->sync(CreditPurchase::query()->sole()))->toBeNull()
        ->and(lpBalance($user))->toBe(0);
})->with([
    'not found' => [200, ['result' => 'error', 'err_code' => 'payment_not_found']],
    'down' => [500, []],
]);

it('syncs only the pending LiqPay purchases inside the window', function () {
    $user = lpUser();
    $fresh = lpGateway()->start($user, 5000, 'UA')['order_id'];
    $due = lpGateway()->start($user, 5000, 'UA')['order_id'];
    $old = lpGateway()->start($user, 5000, 'UA')['order_id'];

    CreditPurchase::query()->where('reference', $due)->update(['created_at' => now()->subMinutes(30)]);
    CreditPurchase::query()->where('reference', $old)->update(['created_at' => now()->subDays(5)]);

    Http::fake([LiqPayCreditPurchases::API_URL => Http::response(['result' => 'ok'] + lpPaid('x'))]);

    $this->artisan('larafoundry:credits-liqpay-sync')->assertSuccessful();

    Http::assertSentCount(1);
    expect(CreditPurchase::query()->where('reference', $due)->value('status'))->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(CreditPurchase::query()->where('reference', $fresh)->value('status'))->toBe(CreditPurchase::STATUS_PENDING)
        ->and(CreditPurchase::query()->where('reference', $old)->value('status'))->toBe(CreditPurchase::STATUS_PENDING);
});
