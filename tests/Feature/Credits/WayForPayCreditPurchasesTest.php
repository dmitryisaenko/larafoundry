<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\SandboxPaymentNotAllowedException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\CreditPurchaseUnavailableException;
use Dmitryisaenko\LaraFoundry\Credits\Gateways\WayForPayCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| Billing stays OFF here (the AuthTestCase default): WayForPay purchases work
| without the billing switch, like the Paddle and LiqPay ones. Signatures in
| these tests are computed by hand from the field orders WayForPay documents,
| never through the gateway's own sign(), so a wrong order cannot agree with
| itself.
*/

const WFP_ACCOUNT = 'shop_lorevid';
const WFP_SECRET = 'wfp_secret_test_key';

beforeEach(function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    config()->set('larafoundry.credits.purchases.wayforpay', [
        'merchant_account' => WFP_ACCOUNT,
        'secret_key' => WFP_SECRET,
        'domain' => 'lorevid.com',
        'sandbox' => false,
    ]);
    config()->set('larafoundry.credits.purchases.currency', 'USD');
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 969]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2]);
    config()->set('larafoundry.credits.purchases.currencies', [
        'UAH' => ['bundles' => [['credits' => 5000, 'price_cents' => 39900]], 'custom' => ['min' => 2500, 'max' => 0]],
    ]);
    config()->set('larafoundry.credits.purchases.routes', [
        'default' => ['gateway' => 'paddle'],
        'countries' => ['UA' => ['gateway' => 'wayforpay', 'currency' => 'UAH']],
    ]);
    config()->set('larafoundry-billing.install.name', null);
});

function wfpUser(string $email = 'buyer@x.test'): User
{
    return User::create([
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function wfpGateway(): WayForPayCreditPurchases
{
    return app(WayForPayCreditPurchases::class);
}

function wfpBalance(User $user): int
{
    return app(CreditService::class)->balance($user);
}

/**
 * A payment report as WayForPay sends it, signed over `$signedAmount` (the
 * amount as WayForPay wrote it into the signature; defaults to the JSON one).
 *
 * @return array<string, mixed>
 */
function wfpPaid(
    string $orderId,
    int|float|string $amount = 399,
    string $currency = 'UAH',
    string $status = 'Approved',
    ?string $signedAmount = null,
    string $key = WFP_SECRET,
    string $account = WFP_ACCOUNT,
): array {
    $payment = [
        'merchantAccount' => $account,
        'orderReference' => $orderId,
        'amount' => $amount,
        'currency' => $currency,
        'authCode' => '541963',
        'cardPan' => '41****8217',
        'transactionStatus' => $status,
        'reasonCode' => 1100,
        'issuerBankCountry' => 'Ukraine',
        'issuerBankName' => 'Privatbank',
        'paymentSystem' => 'card',
    ];

    $payment['merchantSignature'] = hash_hmac('md5', implode(';', [
        $account, $orderId, $signedAmount ?? (string) $amount, $currency, '541963', '41****8217', $status, '1100',
    ]), $key);

    return $payment;
}

/** @param array<string, mixed> $payment */
function wfpNotification(array $payment): Request
{
    return Request::create('/wayforpay/notify', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payment));
}

function wfpAllowTesters(bool $allow): void
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

it('verifies a signature WayForPay itself produced (its public test shop, 2026-11-19)', function () {
    config()->set('larafoundry.credits.purchases.wayforpay.merchant_account', WayForPayCreditPurchases::TEST_ACCOUNT);
    config()->set('larafoundry.credits.purchases.wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');

    // A status answer for an order the test shop never saw, exactly as it came.
    $answer = [
        'merchantAccount' => 'test_merch_n1', 'reason' => 'Order Not Found', 'reasonCode' => 1127,
        'orderReference' => 'lf-probe-510abca700c1', 'amount' => '', 'currency' => '', 'authCode' => '',
        'cardPan' => '', 'transactionStatus' => 'Declined', 'merchantSignature' => 'fb5c48bf4732790e16b856c8367b2b07',
    ];

    expect(wfpGateway()->verify($answer))->toBeTrue()
        ->and(wfpGateway()->verify(['transactionStatus' => 'Approved'] + $answer))->toBeFalse();
});

it('opens a checkout at the server price in the route currency, signed in the documented order', function () {
    $user = wfpUser();

    $checkout = wfpGateway()->start($user, 5000, 'UA', ['product' => 'lorevid'],
        returnUrl: 'https://my.lorevid.com/billing/return', serviceUrl: 'https://my.lorevid.com/wayforpay/notify',
        language: 'uk', email: 'buyer@x.test');

    expect($checkout)->toMatchArray([
        'url' => WayForPayCreditPurchases::CHECKOUT_URL,
        'credits' => 5000,
        'bonus_credits' => 0,
        'price_cents' => 39900,
        'currency' => 'UAH',
        'sandbox' => false,
    ]);

    $fields = $checkout['fields'];

    expect($fields)->toMatchArray([
        'merchantAccount' => WFP_ACCOUNT,
        'merchantDomainName' => 'lorevid.com',
        'merchantTransactionType' => 'SALE',
        'orderReference' => $checkout['order_id'],
        'amount' => '399.00',
        'currency' => 'UAH',
        'productPrice' => ['399.00'],
        'productCount' => ['1'],
        'language' => 'UA',
        'returnUrl' => 'https://my.lorevid.com/billing/return',
        'serviceUrl' => 'https://my.lorevid.com/wayforpay/notify',
        'clientEmail' => 'buyer@x.test',
    ])->and($fields['productName'])->toHaveCount(1);

    // Names, then counts, then prices - not the order the form lists them in.
    expect($fields['merchantSignature'])->toBe(hash_hmac('md5', implode(';', [
        WFP_ACCOUNT, 'lorevid.com', $checkout['order_id'], $fields['orderDate'], '399.00', 'UAH',
        $fields['productName'][0], '1', '399.00',
    ]), WFP_SECRET));

    $purchase = CreditPurchase::query()->sole();

    expect($purchase->status)->toBe(CreditPurchase::STATUS_PENDING)
        ->and($purchase->gateway)->toBe('wayforpay')
        ->and($purchase->reference)->toBe($checkout['order_id'])
        ->and($purchase->credits)->toBe(5000)
        ->and($purchase->amount_cents)->toBe(39900)
        ->and($purchase->currency)->toBe('UAH')
        ->and($purchase->sandbox)->toBeFalse()
        ->and($purchase->meta)->toBe(['product' => 'lorevid', 'wayforpay' => ['country' => 'UA', 'pack' => 5000]]);

    expect(wfpBalance($user))->toBe(0);
});

it('takes the domain from app.url when none is set, and lets the browser pick an unknown language', function () {
    config()->set('larafoundry.credits.purchases.wayforpay.domain', null);
    config()->set('app.url', 'https://My.Lorevid.com');

    $fields = wfpGateway()->start(wfpUser(), 5000, 'UA', language: 'tr')['fields'];

    expect($fields['merchantDomainName'])->toBe('my.lorevid.com')
        ->and($fields['language'])->toBe('AUTO')
        ->and($fields)->not->toHaveKey('clientEmail');
});

it('puts the installation name into the order reference and never repeats one', function () {
    config()->set('larafoundry-billing.install.name', 'Lorevid');
    $user = wfpUser();

    $first = wfpGateway()->start($user, 5000, 'UA')['order_id'];
    $second = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    expect($first)->toStartWith('lf-lorevid-')
        ->and($second)->toStartWith('lf-lorevid-')
        ->and($first)->not->toBe($second);
});

it('refuses a buyer whose country pays through another gateway, and an unknown country', function (?string $country) {
    config()->set('larafoundry.credits.purchases.routes.countries.PL', ['gateway' => 'liqpay', 'currency' => 'UAH']);

    expect(fn () => wfpGateway()->start(wfpUser(), 5000, $country))
        ->toThrow(CreditPurchaseUnavailableException::class);

    expect(CreditPurchase::query()->count())->toBe(0);
})->with([['DE'], ['PL'], [null], ['XX']]);

it('adds the route bonus to the pack and charges the pack price', function () {
    config()->set('larafoundry.credits.purchases.routes.countries.MD', ['gateway' => 'wayforpay', 'currency' => 'UAH', 'bonus_percent' => 5]);

    $checkout = wfpGateway()->start(wfpUser(), 5000, 'MD');

    expect($checkout)->toMatchArray(['credits' => 5250, 'bonus_credits' => 250, 'price_cents' => 39900, 'currency' => 'UAH']);

    expect(CreditPurchase::query()->sole())
        ->credits->toBe(5250)
        ->meta->toBe(['wayforpay' => ['country' => 'MD', 'pack' => 5000, 'bonus_percent' => 5]]);
});

it('refuses an amount not on sale in the route currency', function () {
    expect(fn () => wfpGateway()->start(wfpUser(), 7000, 'UA'))
        ->toThrow(CreditPurchaseUnavailableException::class);

    config()->set('larafoundry.credits.purchases.currencies', []);

    expect(fn () => wfpGateway()->start(wfpUser('b@x.test'), 5000, 'UA'))
        ->toThrow(CreditPurchaseUnavailableException::class);
});

it('refuses to open a checkout without the account, the key or a domain', function (string $key, mixed $value) {
    config()->set('larafoundry.credits.purchases.wayforpay.'.$key, $value);
    config()->set('app.url', '');

    if ($key !== 'domain') {
        config()->set('larafoundry.credits.purchases.wayforpay.domain', 'lorevid.com');
    }

    expect(fn () => wfpGateway()->start(wfpUser(), 5000, 'UA'))->toThrow(GatewayNotConfiguredException::class);
    expect(CreditPurchase::query()->count())->toBe(0);
})->with([
    'no account' => ['merchant_account', ''],
    'no key' => ['secret_key', ' '],
    'no domain' => ['domain', null],
]);

it('lets only testers pay in a test shop and marks their purchases', function (string $account, bool $flag) {
    config()->set('larafoundry.credits.purchases.wayforpay.merchant_account', $account);
    config()->set('larafoundry.credits.purchases.wayforpay.sandbox', $flag);

    expect(fn () => wfpGateway()->start(wfpUser(), 5000, 'UA'))->toThrow(SandboxPaymentNotAllowedException::class);
    expect(CreditPurchase::query()->count())->toBe(0);

    wfpAllowTesters(true);
    $checkout = wfpGateway()->start(wfpUser('t@x.test'), 5000, 'UA');

    expect($checkout['sandbox'])->toBeTrue()
        ->and(CreditPurchase::query()->sole()->sandbox)->toBeTrue();
})->with([
    'the public test shop' => [WayForPayCreditPurchases::TEST_ACCOUNT, false],
    'own shop in test mode' => [WFP_ACCOUNT, true],
]);

it('credits a paid notification once, answers it with a signed accept and keeps the card evidence', function () {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    $answer = wfpGateway()->respondTo(wfpNotification(wfpPaid($orderId)));

    expect($answer['orderReference'])->toBe($orderId)
        ->and($answer['status'])->toBe('accept')
        ->and($answer['signature'])->toBe(hash_hmac('md5', $orderId.';accept;'.$answer['time'], WFP_SECRET));

    $purchase = CreditPurchase::query()->sole();

    expect($purchase->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(wfpBalance($user))->toBe(5000)
        ->and($purchase->meta)->toBe(['wayforpay' => [
            'country' => 'UA', 'pack' => 5000, 'card_country' => 'Ukraine', 'card_bank' => 'Privatbank', 'paytype' => 'card',
        ]]);

    // WayForPay sends it again: answered again, nothing moves.
    expect(wfpGateway()->respondTo(wfpNotification(wfpPaid($orderId)))['status'])->toBe('accept')
        ->and(wfpBalance($user))->toBe(5000);
});

it('accepts the amount however WayForPay wrote it into the signature', function (int|float|string $json, string $signed) {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    wfpGateway()->handleRequest(wfpNotification(wfpPaid($orderId, $json, signedAmount: $signed)));

    expect(wfpBalance($user))->toBe(5000);
})->with([
    'number, signed bare' => [399, '399'],
    'number, signed with decimals' => [399, '399.00'],
    'decimal number, signed with decimals' => [399.0, '399.00'],
    'string with decimals, signed bare' => ['399.00', '399'],
    'string, signed as sent' => ['399.00', '399.00'],
]);

it('rejects a notification not signed with our key, or signed over another amount', function (string $case) {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    $payment = match ($case) {
        'another key' => wfpPaid($orderId, key: 'somebody_else'),
        'signed over less' => wfpPaid($orderId, 399, signedAmount: '1'),
        'unsigned' => array_diff_key(wfpPaid($orderId), ['merchantSignature' => 1]),
        'empty' => [],
    };

    expect(fn () => wfpGateway()->respondTo(wfpNotification($payment)))
        ->toThrow(WebhookVerificationException::class);

    expect(wfpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
})->with(['another key', 'signed over less', 'unsigned', 'empty']);

it('credits nothing when less was paid or in another currency', function (int|string $amount, string $currency) {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(wfpGateway()->handleRequest(wfpNotification(wfpPaid($orderId, $amount, $currency))))->toBeNull();

    expect(wfpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
})->with([
    'less' => ['398.99', 'UAH'],
    'dollars' => [399, 'USD'],
]);

it('credits nothing for a payment that is held, pending, refused or expired', function (string $status) {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(wfpGateway()->respondTo(wfpNotification(wfpPaid($orderId, status: $status)))['status'])->toBe('accept');

    expect(wfpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
})->with(['WaitingAuthComplete', 'Pending', 'InProcessing', 'inProcessing', 'Declined', 'Expired']);

it('reads the status whatever its case', function () {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    wfpGateway()->handle(wfpPaid($orderId, status: 'APPROVED'));

    expect(wfpBalance($user))->toBe(5000);
});

it('leaves alone an order this installation has no row for, and another shop\'s payment', function () {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    expect(wfpGateway()->respondTo(wfpNotification(wfpPaid('lf-other-site-01')))['status'])->toBe('accept')
        ->and(wfpGateway()->handle(wfpPaid($orderId, account: 'another_shop')))->toBeNull();

    expect(wfpBalance($user))->toBe(0);
});

it('takes the credits back on a full refund or a void, never below zero', function (string $status) {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];
    wfpGateway()->handle(wfpPaid($orderId));
    app(CreditService::class)->debit($user, 1000, 'spent');

    $purchase = wfpGateway()->handleRequest(wfpNotification(wfpPaid($orderId, status: $status) + ['refundAmount' => 399]));

    expect($purchase?->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and($purchase->credits_reversed)->toBe(4000)
        ->and(wfpBalance($user))->toBe(0);
})->with(['Refunded', 'Voided']);

it('leaves a partial refund to a person', function () {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];
    wfpGateway()->handle(wfpPaid($orderId));

    expect(wfpGateway()->handle(wfpPaid($orderId, status: 'Refunded') + ['refundAmount' => 100]))->toBeNull();

    expect(wfpBalance($user))->toBe(5000)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_COMPLETED);
});

it('asks WayForPay about a pending purchase with a signed request and credits it when paid', function () {
    $user = wfpUser();
    $orderId = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    Http::fake([WayForPayCreditPurchases::API_URL => Http::response(wfpPaid($orderId, '399'))]);

    $purchase = wfpGateway()->sync(CreditPurchase::query()->sole());

    expect($purchase?->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(wfpBalance($user))->toBe(5000);

    Http::assertSent(fn ($request) => $request->url() === WayForPayCreditPurchases::API_URL
        && $request['transactionType'] === 'CHECK_STATUS'
        && $request['merchantAccount'] === WFP_ACCOUNT
        && $request['orderReference'] === $orderId
        && $request['merchantSignature'] === hash_hmac('md5', WFP_ACCOUNT.';'.$orderId, WFP_SECRET));
});

it('leaves a purchase pending when WayForPay knows no such order or cannot be reached', function (int $code, array $body) {
    $user = wfpUser();
    wfpGateway()->start($user, 5000, 'UA');

    Http::fake([WayForPayCreditPurchases::API_URL => Http::response($body, $code)]);

    expect(wfpGateway()->sync(CreditPurchase::query()->sole()))->toBeNull()
        ->and(wfpBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
})->with([
    // How the test shop answers about an order it never saw: "Declined" with 1127.
    'not found' => [200, ['merchantAccount' => WFP_ACCOUNT, 'reasonCode' => 1127, 'reason' => 'Order Not Found', 'transactionStatus' => 'Declined']],
    'bad signature' => [200, ['reasonCode' => 1113, 'reason' => 'Invalid signature']],
    'down' => [500, []],
]);

it('syncs only the pending WayForPay purchases inside the window', function () {
    $user = wfpUser();
    $fresh = wfpGateway()->start($user, 5000, 'UA')['order_id'];
    $due = wfpGateway()->start($user, 5000, 'UA')['order_id'];
    $old = wfpGateway()->start($user, 5000, 'UA')['order_id'];

    CreditPurchase::query()->where('reference', $due)->update(['created_at' => now()->subMinutes(30)]);
    CreditPurchase::query()->where('reference', $old)->update(['created_at' => now()->subDays(5)]);

    Http::fake([WayForPayCreditPurchases::API_URL => Http::response(wfpPaid('x'))]);

    $this->artisan('larafoundry:credits-wayforpay-sync')->assertSuccessful();

    Http::assertSentCount(1);
    expect(CreditPurchase::query()->where('reference', $due)->value('status'))->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and(CreditPurchase::query()->where('reference', $fresh)->value('status'))->toBe(CreditPurchase::STATUS_PENDING)
        ->and(CreditPurchase::query()->where('reference', $old)->value('status'))->toBe(CreditPurchase::STATUS_PENDING);
});

it('does nothing in the sync without a configured shop', function () {
    config()->set('larafoundry.credits.purchases.wayforpay.secret_key', null);
    Http::fake();

    $this->artisan('larafoundry:credits-wayforpay-sync')->assertSuccessful();

    Http::assertNothingSent();
});
