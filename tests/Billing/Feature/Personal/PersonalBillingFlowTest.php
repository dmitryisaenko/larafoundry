<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Events\TrialStarted;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\PaymentSucceededNotification;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\SubscriptionExpiringNotification;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\CheckoutGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The owner billing screens in `personal` mode: every one of them used to 403 or render
 * an empty shell, because the controller resolved the payer through
 * `getActiveCompany()` — permanently null when there are no companies. The subject is
 * now the signed-in user, and the flow reaches the gateway exactly as it does for a
 * company.
 */
function personalPayer(string $slug, array $attributes = []): BillableUser
{
    $user = BillableUser::query()->create([
        'name' => 'Person '.$slug,
        'email' => "person+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'country' => 'ZZ', // unmapped → the default currency / gateway
    ]);

    if ($attributes !== []) {
        $user->forceFill($attributes)->save();
    }

    return $user->refresh();
}

beforeEach(fn () => CheckoutGateway::$lastOptions = []);

it('resolves the signed-in user as the paying subject', function () {
    $user = personalPayer('subject');

    $this->actingAs($user);

    expect(BillingSubject::isPersonal())->toBeTrue()
        ->and(BillingSubject::current()?->getKey())->toBe($user->getKey())
        ->and(BillingSubject::key(BillingSubject::current()))->toBe($user->getKey())
        ->and(BillingSubject::tenant(BillingSubject::current())?->getTenantKey())->toBe($user->getKey())
        ->and(BillingSubject::label(BillingSubject::current()))->toBe($user->name);
});

it('renders the pricing page for a user with no company at all', function () {
    $user = personalPayer('pricing');

    $this->actingAs($user)
        ->get('/billing/pricing', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Pricing')
        ->assertJsonPath('props.gatewayConfigured', true)
        ->assertJsonPath('props.company.name', $user->name)
        ->assertJsonStructure(['props' => ['plans', 'currency', 'blockStatus']]);
});

it('renders the checkout page for a valid plan', function () {
    $this->actingAs(personalPayer('checkout'))
        ->get('/billing/checkout?plan=pro&period=yearly', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Checkout')
        ->assertJsonPath('props.plan.id', 'pro');
});

it('hands a paid plan to the gateway checkout and redirects away', function () {
    $this->actingAs(personalPayer('paid'))
        ->post('/billing/payment', ['plan_id' => 'pro', 'billing_period' => 'monthly'])
        ->assertRedirect('https://checkout.test/pro/monthly');
});

it('activates the app-side free month on the user itself', function () {
    Event::fake([TrialStarted::class]);
    $user = personalPayer('free');

    $this->actingAs($user)
        ->post('/billing/payment', [
            'plan_id' => 'pro',
            'billing_period' => 'monthly',
            'use_free_month' => true,
        ])
        ->assertRedirect(route('billing.history'));

    $user->refresh();

    expect($user->trial_ends_at)->not->toBeNull()
        ->and($user->free_month_used_at)->not->toBeNull()
        ->and($user->plan_id)->toBe('pro')
        ->and($user->hasAccess())->toBeTrue();

    Event::assertDispatched(TrialStarted::class);
});

it('lists the payment history keyed by the user id', function () {
    $user = personalPayer('history');

    CompanyPayment::query()->create([
        'company_id' => $user->getKey(),
        'user_id' => $user->getKey(),
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'amount' => 1900,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'payment_method' => 'stripe',
    ]);

    // A payment belonging to somebody else must not leak into this history.
    $other = personalPayer('history-other');
    CompanyPayment::query()->create([
        'company_id' => $other->getKey(),
        'user_id' => $other->getKey(),
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'amount' => 4900,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
    ]);

    $this->actingAs($user)
        ->get('/billing/history', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/PaymentHistory')
        ->assertJsonCount(1, 'props.payments')
        ->assertJsonPath('props.payments.0.amount', 1900);
});

it('renders the blocked page instead of falling over on a null company', function () {
    $this->actingAs(personalPayer('blocked'))
        ->get('/billing/blocked?type=payment_required', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Blocked')
        ->assertJsonPath('props.blockType', 'payment_required')
        ->assertJsonPath('props.blockStatus.company_blocked', true)
        ->assertJsonPath('props.blockStatus.company_block_reason', 'first_payment_required');
});

it('routes the region by the person\'s own country', function () {
    // Region routing reads `country` off the payer. Wrapping a User in SubjectTenant must
    // not lose that — otherwise every B2C customer silently bills in the default currency.
    $user = personalPayer('region');
    $user->forceFill(['country' => 'UA'])->save();

    $this->actingAs($user->refresh())
        ->get('/billing/pricing', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.currency', 'UAH');
});

it('emails the payment receipt to the person who paid', function () {
    // There is no founder to resolve in personal mode; without the mode branch the
    // receipt would go to nobody at all.
    config(['larafoundry-billing.notifications.enabled' => true]);
    Notification::fake();

    $user = personalPayer('receipt');

    CompanyPaymentProcessed::dispatch(CompanyPayment::query()->create([
        'company_id' => $user->getKey(),
        'user_id' => $user->getKey(),
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'amount' => 1900,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ]));

    Notification::assertSentTo($user, PaymentSucceededNotification::class);
});

it('warns the person before their own subscription expires', function () {
    // The cron used to query `companies`, which is empty by definition here — every
    // renewal reminder in a B2C product went to nobody and the run reported "notified 0".
    Notification::fake();

    $user = personalPayer('expiring');
    $user->forceFill(['subscription_ends_at' => now()->addDays(10)])->save();

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentTo(
        $user,
        SubscriptionExpiringNotification::class,
        fn (SubscriptionExpiringNotification $n): bool => $n->daysUntilExpiry === 10,
    );
});

it('skips the reminder for a banned person', function () {
    Notification::fake();

    $user = personalPayer('expiring-banned');
    $user->forceFill([
        'subscription_ends_at' => now()->addDays(10),
        'user_blocked_at' => now(),
    ])->save();

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('labels the payer as a customer, not a company', function () {
    expect(BillingSubject::labels())->toBe(['title' => 'Customer', 'name' => 'Customer name']);
});

it('resolves no subject for a guest', function () {
    // The screens sit behind `auth`, but the seam itself must still fail closed rather
    // than inventing a payer — every caller treats null as "no subject".
    expect(BillingSubject::for(null))->toBeNull()
        ->and(BillingSubject::current())->toBeNull()
        ->and(BillingSubject::key(null))->toBeNull()
        ->and(BillingSubject::tenant(null))->toBeNull()
        ->and(BillingSubject::label(null))->toBeNull();
});
