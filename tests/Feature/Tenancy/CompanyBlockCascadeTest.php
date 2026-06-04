<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\EnsureActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The company-block cascade (phase 3.3) is enforced at the tenancy boundary:
 * EnsureActiveTenant denies the tenant screens to every member whose active
 * company is blocked. We drive the middleware directly with a stub user whose
 * getActiveCompany() returns the company under test, so the test is about the
 * enforcement decision, not the session plumbing (covered by TenantResolverTest).
 */

beforeEach(function () {
    config(['larafoundry.tenancy.mode' => 'teams']);
});

/**
 * A minimal user whose active company is fixed, with a route name so the
 * exemption check has something to read.
 */
function blockUser(?Company $active, bool $hasFallback = false): object
{
    return new class($active, $hasFallback)
    {
        public bool $promoted = false;

        public function __construct(public ?Company $active, public bool $hasFallback) {}

        public function getActiveCompany(): ?Company
        {
            return $this->active;
        }

        // Mirrors BelongsToTenancy::setNextAvailableCompany: promotes an
        // unblocked company if one exists, returning whether it did.
        public function setNextAvailableCompany(): bool
        {
            $this->promoted = true;
            $this->active = null;

            return $this->hasFallback;
        }
    };
}

function tenantRequest(object $user): Request
{
    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->headers->set('Accept', 'application/json'); // take the 403 branch

    return $request;
}

it('denies a single-company member whose only company is blocked', function () {
    // No unblocked fallback → the member cannot be rescued, so the cascade
    // rejects (403 for an XHR request).
    $company = (new Company)->forceFill(['company_blocked_at' => now()]);
    $request = tenantRequest(blockUser($company, hasFallback: false));

    $reached = false;
    expect(fn () => (new EnsureActiveTenant)->handle($request, function () use (&$reached) {
        $reached = true;

        return new Response('ok');
    }))->toThrow(HttpException::class);

    expect($reached)->toBeFalse();
});

it('self-heals a multi-company member onto an unblocked company', function () {
    // An unblocked fallback exists → the member is promoted to it and the
    // original request is replayed (302), never reaching the blocked screen.
    $company = (new Company)->forceFill(['company_blocked_at' => now()]);
    $user = blockUser($company, hasFallback: true);
    $request = tenantRequest($user);

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($user->promoted)->toBeTrue()
        ->and($response->isRedirect())->toBeTrue();
});

it('lets a member through when their active company is not blocked', function () {
    $company = (new Company)->forceFill(['company_blocked_at' => null]);
    $request = tenantRequest(blockUser($company));

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('still redirects to create when there is no active company at all', function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => blockUser(null));

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    // A guest-of-tenant (no company) is sent to the creation flow, not the
    // blocked screen — the two states are distinct.
    expect($response->isRedirect())->toBeTrue();
});

it('passes through untouched in personal mode', function () {
    config(['larafoundry.tenancy.mode' => 'personal']);
    $company = (new Company)->forceFill(['company_blocked_at' => now()]);
    $request = tenantRequest(blockUser($company));

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});
