<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Notifications\SubscriptionExpiringNotification;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The expiry-reminder cron: warns owners a fixed number of days before
 * `subscription_ends_at`, idempotently, skipping banned owners — and never when billing
 * is off. Unlike the donor's `// TODO` stub, it sends a real queued mail.
 */
function expiringCompany(string $slug, ?int $daysUntilExpiry, bool $ownerBanned = false): array
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'user_blocked_at' => $ownerBanned ? now() : null,
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'ZZ',
        'created_by_id' => $owner->getKey(),
        'subscription_ends_at' => $daysUntilExpiry === null ? null : now()->addDays($daysUntilExpiry),
    ])->save();

    return [$company->refresh(), $owner];
}

it('notifies the owner when a subscription expires in 10 days', function () {
    Notification::fake();
    [, $owner] = expiringCompany('exp10', 10);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentTo(
        $owner,
        SubscriptionExpiringNotification::class,
        fn (SubscriptionExpiringNotification $n) => $n->daysUntilExpiry === 10 && $n instanceof ShouldQueue,
    );
});

it('notifies the owner when a subscription expires in 3 days', function () {
    Notification::fake();
    [, $owner] = expiringCompany('exp3', 3);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentTo(
        $owner,
        SubscriptionExpiringNotification::class,
        fn (SubscriptionExpiringNotification $n) => $n->daysUntilExpiry === 3,
    );
});

it('does not notify outside the reminder windows', function () {
    Notification::fake();
    expiringCompany('exp7', 7);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not notify a company without a subscription end date', function () {
    Notification::fake();
    expiringCompany('exp-null', null);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('is idempotent across runs on the same day', function () {
    Notification::fake();
    [, $owner] = expiringCompany('exp-idem', 10);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();
    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentToTimes($owner, SubscriptionExpiringNotification::class, 1);
});

it('skips a company whose owner is banned', function () {
    Notification::fake();
    expiringCompany('exp-banned', 10, ownerBanned: true);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('carries the company id and name as scalars, not the model (cron-polish)', function () {
    Notification::fake();
    [$company] = expiringCompany('exp-scalar', 10);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentTo(
        PaymentUser::query()->findOrFail($company->created_by_id),
        SubscriptionExpiringNotification::class,
        fn (SubscriptionExpiringNotification $n) => $n->companyId === $company->getKey()
            && $n->companyName === 'Acme'
            && ! str_contains(serialize($n), 'BillableCompany'),
    );
});

it('does nothing when billing is disabled', function () {
    config(['larafoundry.billing.enabled' => false]);
    Notification::fake();
    expiringCompany('exp-off', 10);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('honours a custom reminder-window config', function () {
    config(['larafoundry-billing.enforcement.expiry_reminders' => [5]]);
    Notification::fake();
    [, $five] = expiringCompany('exp5', 5);
    [, $ten] = expiringCompany('exp10-ignored', 10);

    $this->artisan('larafoundry-billing:check-expiring')->assertSuccessful();

    Notification::assertSentToTimes($five, SubscriptionExpiringNotification::class, 1);
    Notification::assertNotSentTo($ten, SubscriptionExpiringNotification::class);
});
