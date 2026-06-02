<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\PersonalTenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Support\UserTenant;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

uses(RefreshDatabase::class);

/*
 * The resolver is the swappable seam (phase 6 swaps it for a token resolver).
 * Teams reads/writes the active company on user_sessions (per-device); personal
 * treats the user as its own tenant. We exercise the session resolver against a
 * real started session so the user_sessions round-trip is genuine.
 */

function resolverUser(): User
{
    return User::create(['name' => 'U', 'email' => 'u'.uniqid().'@x.test', 'password' => 'secret-pass']);
}

function startedSession(string $label = 'sess'): Store
{
    $id = str_pad(preg_replace('/[^a-zA-Z0-9]/', '', $label), 40, 'a');
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $id);
    $store->start();
    app()->instance('session.store', $store);
    request()->setLaravelSession($store);

    return $store;
}

function ownedCompany(User $owner): Company
{
    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

describe('teams mode (SessionTenantResolver)', function () {
    it('returns null when no company is active', function () {
        startedSession();
        $resolver = app(SessionTenantResolver::class);

        expect($resolver->current(resolverUser()))->toBeNull();
    });

    it('persists the active company onto the tracked session row', function () {
        $store = startedSession();
        $user = resolverUser();
        $company = ownedCompany($user);

        // A tracked session row must exist for the per-device write to land.
        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $store->getId(),
            'login_method' => 'native',
        ]);

        $resolver = app(SessionTenantResolver::class);
        $resolver->setCurrent($user, $company);

        expect(UserSession::where('session_id', $store->getId())->value('active_company_id'))
            ->toBe($company->id)
            ->and($resolver->current($user)?->getTenantKey())->toBe($company->id);
    });

    it('does not resolve a company the user no longer belongs to', function () {
        $store = startedSession();
        $user = resolverUser();
        $company = ownedCompany($user);
        UserSession::create(['user_id' => $user->id, 'session_id' => $store->getId(), 'login_method' => 'native']);

        $resolver = app(SessionTenantResolver::class);
        $resolver->setCurrent($user, $company);

        // Remove them from the company; the stale id must no longer resolve.
        $company->removeEmployee($user);

        expect($resolver->current($user->fresh()))->toBeNull();
    });

    it('forget clears the active company', function () {
        $store = startedSession();
        $user = resolverUser();
        $company = ownedCompany($user);
        UserSession::create(['user_id' => $user->id, 'session_id' => $store->getId(), 'login_method' => 'native']);

        $resolver = app(SessionTenantResolver::class);
        $resolver->setCurrent($user, $company);
        $resolver->forget($user);

        expect($resolver->current($user))->toBeNull();
    });
});

describe('personal mode (PersonalTenantResolver)', function () {
    it('always resolves the user as its own tenant', function () {
        $user = resolverUser();
        $resolver = new PersonalTenantResolver;

        $tenant = $resolver->current($user);

        expect($tenant)->toBeInstanceOf(UserTenant::class)
            ->and($tenant->getTenantKey())->toBe($user->id);
    });

    it('setCurrent/forget are no-ops', function () {
        $user = resolverUser();
        $resolver = new PersonalTenantResolver;

        $resolver->setCurrent($user, 5);
        $resolver->forget($user);

        expect($resolver->current($user)->getTenantKey())->toBe($user->id);
    });
});
