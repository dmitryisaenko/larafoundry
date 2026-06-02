<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
 * Shared helpers for the phase-1.3 authorization suite. Prefixed `rbac*` so they
 * never collide with the tenancy suite's global `member()`/`companyOwnedBy()`.
 * Loaded via composer autoload-dev `files`.
 */

if (! function_exists('rbacSeed')) {
    /**
     * Seed the catalog (permissions, the `authenticated` global role, the `member`
     * template) into the database from config — what `larafoundry:install` runs.
     */
    function rbacSeed(): void
    {
        Artisan::call('larafoundry:permissions:sync');
    }
}

if (! function_exists('rbacUser')) {
    function rbacUser(string $email = 'u@x.test', array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'U',
            'email' => $email,
            'password' => 'secret-pass',
            'email_verified_at' => now(),
        ], $attributes));
    }
}

if (! function_exists('rbacCompany')) {
    function rbacCompany(User $owner, string $name = 'Acme'): Company
    {
        $company = Company::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'created_by_id' => $owner->id,
        ]);
        $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

        return $company;
    }
}

if (! function_exists('rbacActivate')) {
    /**
     * Make $company the active tenant for $user over a genuine session round-trip
     * (real session store + tracked user_sessions row + resolver), the way the
     * HTTP layer expects — independent of a session cookie surviving requests.
     */
    function rbacActivate(User $user, Company $company): void
    {
        $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_pad('rbac'.$user->id, 40, 'a'));
        $store->start();
        app()->instance('session.store', $store);
        request()->setLaravelSession($store);

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $store->getId(),
            'login_method' => 'native',
        ]);

        app(SessionTenantResolver::class)->setCurrent($user, $company);
    }
}
