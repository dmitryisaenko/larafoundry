<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Onboarding\Support\OnboardingBuilder;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The onboarding checklist built end-to-end through the singleton builder seeded
 * with the core step provider (phase 5.4): auto-detected completion, the
 * dismissed short-circuit, the guest/null case and the personal-mode omission of
 * the two company steps.
 */
uses(RefreshDatabase::class);

/**
 * The core builder, flushed so a re-build after a state change re-computes rather
 * than returning the per-request memo.
 */
function onboarding(): OnboardingBuilder
{
    $builder = app(OnboardingBuilder::class);
    $builder->flush();

    return $builder;
}

/** Index the built steps by key for terse assertions. */
function stepsByKey(array $result): array
{
    return collect($result['steps'])->keyBy('key')->all();
}

it('gives a fresh tenant user four incomplete steps', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    $result = onboarding()->build($user);
    $steps = stepsByKey($result);

    expect($result['dismissed'])->toBeFalse()
        ->and($result['total'])->toBe(4)
        ->and(array_keys($steps))->toEqual(['profile', 'two_factor', 'company_setup', 'invite_teammate'])
        ->and($steps['profile']['complete'])->toBeFalse()
        ->and($steps['two_factor']['complete'])->toBeFalse()
        // Company was just created (country null) and has only the owner.
        ->and($steps['company_setup']['complete'])->toBeFalse()
        ->and($steps['invite_teammate']['complete'])->toBeFalse()
        ->and($result['completed'])->toBe(0)
        ->and($result['all_complete'])->toBeFalse();
});

it('marks the profile step complete once name and lastname are set', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    expect(stepsByKey(onboarding()->build($user))['profile']['complete'])->toBeFalse();

    $user->forceFill(['name' => 'Ada', 'lastname' => 'Lovelace'])->save();

    expect(stepsByKey(onboarding()->build($user))['profile']['complete'])->toBeTrue();
});

it('marks the 2FA step complete once two-factor is confirmed', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    expect(stepsByKey(onboarding()->build($user))['two_factor']['complete'])->toBeFalse();

    $user->forceFill([
        'two_factor_secret' => 'enc-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    expect(stepsByKey(onboarding()->build($user))['two_factor']['complete'])->toBeTrue();
});

it('marks the company step complete once the company leaves setup', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    expect(stepsByKey(onboarding()->build($user))['company_setup']['complete'])->toBeFalse();

    // isInSetup() is false once the company has a country. setActiveCompany drops
    // the tenant resolver's per-request memo (the stale country=null instance) so
    // the next resolve re-reads the updated row — the HTTP layer resolves fresh per
    // request, this stands in for that.
    $company->update(['country' => 'UA']);
    $user->setActiveCompany($company);

    expect(stepsByKey(onboarding()->build($user))['company_setup']['complete'])->toBeTrue();
});

it('marks the invite step complete once a second member joins', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    rbacActivate($owner, $company);

    expect(stepsByKey(onboarding()->build($owner))['invite_teammate']['complete'])->toBeFalse();

    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    expect(stepsByKey(onboarding()->build($owner))['invite_teammate']['complete'])->toBeTrue();
});

it('resolves cta urls for the core steps whose routes exist', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    $steps = stepsByKey(onboarding()->build($user));

    // profile.index / settings.company / tenancy.employees.index are all
    // registered routes, so every core step resolves a CTA url (guards against
    // a step silently shipping a dead button on a route rename).
    expect($steps['profile']['cta_url'])->not->toBeNull()
        ->and($steps['company_setup']['cta_url'])->not->toBeNull()
        ->and($steps['invite_teammate']['cta_url'])->not->toBeNull();
});

it('returns null for a guest', function () {
    expect(onboarding()->build(null))->toBeNull();
});

it('short-circuits to a dismissed payload without computing steps', function () {
    $user = rbacUser();
    $company = rbacCompany($user);
    rbacActivate($user, $company);

    app(SettingsRepository::class)->set('onboarding.dismissed', true, $user->id);

    $result = onboarding()->build($user);

    expect($result['dismissed'])->toBeTrue()
        ->and($result['steps'])->toBe([])
        ->and($result['total'])->toBe(0)
        ->and($result['completed'])->toBe(0)
        ->and($result['all_complete'])->toBeFalse();
});

it('omits the two company steps in personal mode', function () {
    config()->set('larafoundry.tenancy.mode', 'personal');

    $user = rbacUser();

    $result = onboarding()->build($user);

    expect(array_column($result['steps'], 'key'))->toEqual(['profile', 'two_factor'])
        ->and($result['total'])->toBe(2);
});

it('returns null for the platform super-admin', function () {
    $admin = rbacUser('boss@x.test', ['is_admin' => true]);

    expect(onboarding()->build($admin))->toBeNull();
});
