<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\SandboxPaymentNotAllowedException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\CreditPurchaseUnavailableException;
use Dmitryisaenko\LaraFoundry\Credits\Gateways\PaddleCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
| Billing stays OFF in this suite (the AuthTestCase default): buying credits must
| work without the billing switch, for a host that keeps its own notification
| address and hands the payload in.
*/

beforeEach(function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    config()->set('cashier.sandbox', false);
    config()->set('larafoundry-billing.paddle.api_key', 'pdl_live_apikey_test');
    config()->set('larafoundry-billing.paddle.client_side_token', 'live_client_token');
    config()->set('larafoundry.credits.purchases.paddle.product_id', 'pro_credits');
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 969]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2]);
});

function ppUser(string $email = 'buyer@x.test'): User
{
    return User::create([
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function ppPaddle(): PaddleCreditPurchases
{
    return app(PaddleCreditPurchases::class);
}

function ppFakePaddle(string $id = 'txn_01'): void
{
    Http::fake(['*/transactions' => Http::response(['data' => ['id' => $id]], 201)]);
}

/**
 * A `transaction.completed` notification for the transaction, as Paddle sends it.
 *
 * @param  array<string, mixed>  $custom
 * @return array<string, mixed>
 */
function ppCompleted(string $id, int $grandTotal, array $custom = ['kind' => PaddleCreditPurchases::KIND], string $currency = 'USD'): array
{
    return [
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => $id,
            'status' => 'completed',
            'currency_code' => $currency,
            'custom_data' => $custom,
            'details' => ['totals' => ['grand_total' => (string) $grandTotal]],
        ],
    ];
}

/** @return array<string, mixed> */
function ppAdjustment(string $transactionId, string $action = 'refund', string $status = 'approved', ?string $type = 'full'): array
{
    return [
        'event_type' => 'adjustment.created',
        'data' => array_filter([
            'transaction_id' => $transactionId,
            'action' => $action,
            'status' => $status,
            'type' => $type,
        ], fn ($v) => $v !== null),
    ];
}

function ppBalance(User $user): int
{
    return app(CreditService::class)->balance($user);
}

it('opens a transaction at the server price and records it pending', function () {
    ppFakePaddle();
    $user = ppUser();

    $checkout = ppPaddle()->start($user, 5000, ['product' => 'lorevid']);

    expect($checkout)->toMatchArray([
        'transaction_id' => 'txn_01',
        'credits' => 5000,
        'price_cents' => 969,
        'currency' => 'USD',
        'client_side_token' => 'live_client_token',
        'sandbox' => false,
    ]);

    $purchase = CreditPurchase::query()->sole();

    expect($purchase->status)->toBe(CreditPurchase::STATUS_PENDING)
        ->and($purchase->amount_cents)->toBe(969)
        ->and($purchase->owner_id)->toBe($user->id)
        ->and($purchase->meta)->toBe(['product' => 'lorevid']);

    Http::assertSent(function (ClientRequest $request) use ($user) {
        $item = $request['items'][0];

        return $request->url() === 'https://api.paddle.com/transactions'
            && $request->hasHeader('Authorization', 'Bearer pdl_live_apikey_test')
            && $item['quantity'] === 1
            && $item['price']['product_id'] === 'pro_credits'
            && $item['price']['unit_price'] === ['amount' => '969', 'currency_code' => 'USD']
            && $request['custom_data']['kind'] === PaddleCreditPurchases::KIND
            && $request['custom_data']['user_id'] === (string) $user->id
            && $request['custom_data']['product'] === 'lorevid'
            && ! isset($request['checkout']);
    });
});

it('prices a custom amount from the price list, rounding up', function () {
    ppFakePaddle();

    expect(ppPaddle()->start(ppUser(), 3333)['price_cents'])->toBe(667);
});

it('sends the return address only when the host gives one', function () {
    ppFakePaddle();

    ppPaddle()->start(ppUser(), 5000, returnUrl: 'https://my.example.test/billing');

    Http::assertSent(fn (ClientRequest $request) => $request['checkout'] === ['url' => 'https://my.example.test/billing']);
});

it('refuses an amount that is not on sale, before calling Paddle', function () {
    Http::fake();

    expect(fn () => ppPaddle()->start(ppUser(), 100))->toThrow(CreditPurchaseUnavailableException::class);

    Http::assertNothingSent();
});

it('refuses to start without a product to price against', function () {
    Http::fake();
    config()->set('larafoundry.credits.purchases.paddle.product_id', null);

    expect(fn () => ppPaddle()->start(ppUser(), 5000))->toThrow(GatewayNotConfiguredException::class);

    Http::assertNothingSent();
});

it('fails loudly when the sandbox flag disagrees with a live key', function () {
    Http::fake();
    config()->set('cashier.sandbox', true);
    app()->instance(SandboxTesters::class, new class implements SandboxTesters
    {
        public function allows(?Authenticatable $user): bool
        {
            return true;
        }
    });

    expect(fn () => ppPaddle()->start(ppUser(), 5000))->toThrow(GatewayNotConfiguredException::class);

    Http::assertNothingSent();
});

it('fails loudly when live mode runs on a sandbox key', function () {
    Http::fake();
    config()->set('larafoundry-billing.paddle.api_key', 'pdl_sdbx_apikey_test');

    expect(fn () => ppPaddle()->start(ppUser(), 5000))->toThrow(GatewayNotConfiguredException::class);

    Http::assertNothingSent();
});

it('lets only the testers pay while in the sandbox', function () {
    Http::fake(['*/transactions' => Http::response(['data' => ['id' => 'txn_sb']], 201)]);
    config()->set('cashier.sandbox', true);
    config()->set('larafoundry-billing.paddle.api_key', 'pdl_sdbx_apikey_test');
    $tester = ppUser('tester@x.test');
    $customer = ppUser('customer@x.test');

    app()->instance(SandboxTesters::class, new class($tester) implements SandboxTesters
    {
        public function __construct(private readonly User $tester) {}

        public function allows(?Authenticatable $user): bool
        {
            return $user?->getAuthIdentifier() === $this->tester->id;
        }
    });

    expect(ppPaddle()->canPay($customer))->toBeFalse()
        ->and(fn () => ppPaddle()->start($customer, 5000))->toThrow(SandboxPaymentNotAllowedException::class);

    Http::assertNothingSent();

    $checkout = ppPaddle()->start($tester, 5000);

    expect($checkout['sandbox'])->toBeTrue()
        ->and(CreditPurchase::query()->sole()->sandbox)->toBeTrue();

    Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'https://sandbox-api.paddle.com/'));
});

it('lets nobody pay in the sandbox by default', function () {
    config()->set('cashier.sandbox', true);

    expect(ppPaddle()->canPay(ppUser()))->toBeFalse();
});

it('lets everyone pay on live keys without asking the testers', function () {
    expect(ppPaddle()->canPay(ppUser()))->toBeTrue()
        ->and(ppPaddle()->canPay(null))->toBeTrue();
});

it('creates no purchase when Paddle refuses the transaction', function () {
    Http::fake(['*/transactions' => Http::response(['error' => ['detail' => 'nope']], 400)]);

    expect(fn () => ppPaddle()->start(ppUser(), 5000))->toThrow(GatewayOperationException::class)
        ->and(CreditPurchase::query()->count())->toBe(0);
});

it('credits a completed transaction once, from the pending purchase', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    ppPaddle()->handle(ppCompleted('txn_01', 969));
    ppPaddle()->handle(ppCompleted('txn_01', 969));
    ppPaddle()->handle(array_replace_recursive(ppCompleted('txn_01', 969), ['event_type' => 'transaction.paid', 'data' => ['status' => 'paid']]));

    expect(ppBalance($user))->toBe(5000)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_COMPLETED);
});

it('never takes the amount from the data that travelled with the payment', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    // custom_data claims far more credits - the pending purchase decides.
    ppPaddle()->handle(ppCompleted('txn_01', 969, ['kind' => PaddleCreditPurchases::KIND, 'credits' => '500000', 'expected_cents' => '1']));

    expect(ppBalance($user))->toBe(5000);
});

it('credits nothing when less was paid than the server price', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    ppPaddle()->handle(ppCompleted('txn_01', 500));

    expect(ppBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
});

it('leaves alone a transaction that is not a credit purchase', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    expect(ppPaddle()->creditFromTransaction(ppCompleted('txn_01', 969, ['kind' => 'youtuber_channel'])['data']))->toBeNull()
        ->and(ppPaddle()->creditFromTransaction(ppCompleted('txn_01', 969, [])['data']))->toBeNull()
        ->and(ppBalance($user))->toBe(0);
});

it('leaves alone a transaction that is not settled', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    ppPaddle()->handle(array_replace_recursive(ppCompleted('txn_01', 969), ['data' => ['status' => 'billed']]));

    expect(ppBalance($user))->toBe(0);
});

it('leaves alone another installation\'s payment', function () {
    config()->set('larafoundry-billing.install.name', 'lorevid');
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    Http::assertSent(fn (ClientRequest $request) => $request['custom_data']['install'] === 'lorevid');

    ppPaddle()->handle(ppCompleted('txn_01', 969, ['kind' => PaddleCreditPurchases::KIND, 'install' => 'commentio']));

    expect(ppBalance($user))->toBe(0);

    ppPaddle()->handle(ppCompleted('txn_01', 969, ['kind' => PaddleCreditPurchases::KIND, 'install' => 'lorevid']));

    expect(ppBalance($user))->toBe(5000);
});

it('takes the credits back on an approved full refund', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);
    ppPaddle()->handle(ppCompleted('txn_01', 969));

    ppPaddle()->handle(ppAdjustment('txn_01'));

    expect(ppBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_REFUNDED);
});

it('takes the credits back on a chargeback, which carries no type', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);
    ppPaddle()->handle(ppCompleted('txn_01', 969));

    ppPaddle()->handle(ppAdjustment('txn_01', 'chargeback', type: null));

    expect(ppBalance($user))->toBe(0);
});

it('does not act on a pending or a partial refund', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);
    ppPaddle()->handle(ppCompleted('txn_01', 969));

    ppPaddle()->handle(ppAdjustment('txn_01', status: 'pending_approval'));
    ppPaddle()->handle(ppAdjustment('txn_01', type: 'partial'));
    ppPaddle()->handle(ppAdjustment('txn_01', 'credit'));

    expect(ppBalance($user))->toBe(5000);
});

it('ignores a refund of a transaction that is not a credit purchase here, without asking Paddle', function () {
    Http::fake();

    ppPaddle()->handle(ppAdjustment('txn_somebody_elses'));

    expect(CreditPurchase::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('settles a refund that came before the confirmation with nothing credited', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    ppPaddle()->handle(ppAdjustment('txn_01'));
    ppPaddle()->handle(ppCompleted('txn_01', 969));

    expect(ppBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_REFUNDED);
});

it('acts only on a request whose signature checks out', function () {
    ppFakePaddle();
    config()->set('larafoundry-billing.paddle.webhook.secret', 'whsec_test');
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    $body = json_encode(ppCompleted('txn_01', 969));
    $ts = now()->getTimestamp();
    $request = fn (string $secret) => Request::create('/billing/paddle/webhook', 'POST', server: [
        'HTTP_PADDLE_SIGNATURE' => "ts={$ts};h1=".hash_hmac('sha256', "{$ts}:{$body}", $secret),
    ], content: $body);

    expect(fn () => ppPaddle()->handleRequest($request('wrong')))->toThrow(WebhookVerificationException::class)
        ->and(ppBalance($user))->toBe(0);

    ppPaddle()->handleRequest($request('whsec_test'));

    expect(ppBalance($user))->toBe(5000);
});

it('opens every transaction with the tax inside the price and a quantity of exactly one', function () {
    ppFakePaddle();

    ppPaddle()->start(ppUser(), 5000);

    Http::assertSent(fn (ClientRequest $request) => $request['items'][0]['price']['tax_mode'] === 'internal'
        && $request['items'][0]['price']['quantity'] === ['minimum' => 1, 'maximum' => 1]);
});

it('credits nothing when a discount brought the total under the price', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);

    // Tax inside the price: 15% off 969 is 824, tax included.
    ppPaddle()->handle(ppCompleted('txn_01', 824));

    expect(ppBalance($user))->toBe(0);
});

it('warns when a purchase opened here comes back marked for another installation', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);
    config()->set('larafoundry-billing.install.name', 'lorevid');
    Log::spy();

    ppPaddle()->handle(ppCompleted('txn_01', 969));

    expect(ppBalance($user))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'larafoundry.credits.paddle.paid_purchase_marked_elsewhere')->once();
});

it('leaves the credits taken when a chargeback is reversed, and says so', function () {
    ppFakePaddle();
    $user = ppUser();
    ppPaddle()->start($user, 5000);
    ppPaddle()->handle(ppCompleted('txn_01', 969));
    ppPaddle()->handle(ppAdjustment('txn_01', 'chargeback', type: null));
    Log::spy();

    ppPaddle()->handle(ppAdjustment('txn_01', 'chargeback_reverse', type: null));

    expect(ppBalance($user))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_REFUNDED);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'larafoundry.credits.paddle.chargeback_reversed')->once();
});
