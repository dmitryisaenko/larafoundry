<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionStatus;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardMetricsService;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

function dmUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'U',
        'email' => 'u'.uniqid().'@x.test',
        'password' => 'secret-pass',
    ], $attrs));
}

function dmCompany(string $name, array $billing = []): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => dmUser()->id,
    ]);

    if ($billing !== []) {
        // Billing columns are not $fillable (mass-assign guard); set them the way
        // the billing add-on would — server-side via forceFill.
        $company->forceFill($billing)->save();
    }

    return $company;
}

it('aggregates user metrics with date, state and activity buckets', function () {
    $service = app(DashboardMetricsService::class);

    // Three users created "today" (now), two of them verified.
    dmUser(['email_verified_at' => now()]);
    $b = dmUser(['email_verified_at' => now()]);
    $c = dmUser(); // unverified

    // One user from two years ago — counts toward total only.
    dmUser()->forceFill(['created_at' => now()->subYears(2)])->save();

    // Block the unverified one.
    $c->forceFill(['user_blocked_at' => now()])->save();

    // Recently active: one now, one three days ago (both inside the 30-day window).
    dmUser(['email_verified_at' => now(), 'last_activity_at' => now()]);
    $b->forceFill(['last_activity_at' => now()->subDays(3)])->save();

    $metrics = $service->users();

    expect($metrics['total'])->toBe(5)        // 3 today + 1 old + 1 active-now
        ->and($metrics['new_today'])->toBe(4) // all but the two-years-ago one
        ->and($metrics['new_month'])->toBe(4)
        ->and($metrics['new_year'])->toBe(4)
        ->and($metrics['verified'])->toBe(3)  // two seeded + the active-now one
        ->and($metrics['blocked'])->toBe(1)
        ->and($metrics['active'])->toBe(2);   // now + 3-days-ago
});

it('honours the active-within-days window for the active user count', function () {
    config(['larafoundry.admin.active_within_days' => 7]);
    $service = app(DashboardMetricsService::class);

    dmUser(['last_activity_at' => now()->subDays(2)]);  // inside 7d
    dmUser(['last_activity_at' => now()->subDays(20)]); // outside 7d

    expect($service->users()['active'])->toBe(1);
});

it('breaks companies down by subscription status matching SubscriptionStatus semantics', function () {
    $service = app(DashboardMetricsService::class);

    dmCompany('Trial', ['trial_ends_at' => now()->addDays(5)]);
    dmCompany('Active', ['subscription_ends_at' => now()->addDays(60)]);
    dmCompany('Expiring', ['subscription_ends_at' => now()->addDays(3)]);
    dmCompany('Expired', ['subscription_ends_at' => now()->subDays(5)]);
    // A lapsed trial with no subscription → also expired (the other expired path).
    dmCompany('LapsedTrial', ['trial_ends_at' => now()->subDays(5)]);
    dmCompany('Never');
    // A live trial AND a live subscription → on_trial wins (precedence).
    dmCompany('TrialBeatsSub', ['trial_ends_at' => now()->addDays(2), 'subscription_ends_at' => now()->addDays(60)]);

    $metrics = $service->companies();

    expect($metrics['total'])->toBe(7)
        ->and($metrics['new_month'])->toBe(7)
        ->and($metrics['on_trial'])->toBe(2)
        ->and($metrics['active'])->toBe(1)
        ->and($metrics['expiring'])->toBe(1)
        ->and($metrics['expired'])->toBe(2)
        ->and($metrics['never_activated'])->toBe(1);

    // Parity: the SQL aggregate agrees, company by company, with the per-row
    // classifier the admin list uses — they can never drift.
    $expected = ['on_trial' => 0, 'active' => 0, 'expiring' => 0, 'expired' => 0, 'never_activated' => 0];
    foreach (Company::all() as $company) {
        $expected[SubscriptionStatus::for($company)]++;
    }

    expect($metrics['on_trial'])->toBe($expected['on_trial'])
        ->and($metrics['active'])->toBe($expected['active'])
        ->and($metrics['expiring'])->toBe($expected['expiring'])
        ->and($metrics['expired'])->toBe($expected['expired'])
        ->and($metrics['never_activated'])->toBe($expected['never_activated']);
});

it('counts only the last 24h of admin events and feeds the recent list', function () {
    $service = app(DashboardMetricsService::class);

    $causer = dmUser(['name' => 'Boss', 'lastname' => 'Man']);

    ActivityModel::create([
        'log_name' => 'admin',
        'description' => 'admin.user.blocked',
        'causer_type' => $causer->getMorphClass(),
        'causer_id' => $causer->id,
    ])->forceFill(['created_at' => now()])->save();

    ActivityModel::create([
        'log_name' => 'admin',
        'description' => 'admin.company.blocked',
    ])->forceFill(['created_at' => now()->subHours(2)])->save();

    // Older than 24h — present in the feed, excluded from the 24h count.
    ActivityModel::create([
        'log_name' => 'admin',
        'description' => 'admin.user.unblocked',
    ])->forceFill(['created_at' => now()->subDays(2)])->save();

    // A non-admin log entry — excluded from both.
    ActivityModel::create([
        'log_name' => 'default',
        'description' => 'something.else',
    ])->forceFill(['created_at' => now()])->save();

    $metrics = $service->activity();

    expect($metrics['last_24h'])->toBe(2)
        ->and($metrics['recent'])->toHaveCount(3)
        ->and($metrics['recent'][0]['description'])->toBe('admin.user.blocked')
        ->and($metrics['recent'][0]['causer'])->toBe('Boss Man')
        ->and($metrics['recent'][1]['causer'])->toBeNull();

    // The non-admin entry never appears in the feed.
    expect(collect($metrics['recent'])->pluck('description'))->not->toContain('something.else');
});

it('limits the recent feed to the configured size', function () {
    config(['larafoundry.admin.dashboard_activity_limit' => 2]);
    $service = app(DashboardMetricsService::class);

    foreach (range(1, 5) as $i) {
        ActivityModel::create(['log_name' => 'admin', 'description' => "admin.event.{$i}"]);
    }

    expect($service->activity()['recent'])->toHaveCount(2);
});
