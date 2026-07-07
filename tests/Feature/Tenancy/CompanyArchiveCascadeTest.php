<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\EnsureActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The owner-driven archive gate (phase 7) at the tenancy boundary. Narrower than
 * the block: EnsureActiveTenant denies the tenant screens to a NON-owner member
 * whose active company is archived, but lets the OWNER through so they can
 * unarchive it. Driven directly with a stub user so the test is about the
 * enforcement decision, not session plumbing.
 */

beforeEach(function () {
    config(['larafoundry.tenancy.mode' => 'teams']);
});

/**
 * A minimal user whose active company + ownership are fixed.
 */
function archiveUser(?Company $active, bool $isOwner, bool $hasFallback = false): object
{
    return new class($active, $isOwner, $hasFallback)
    {
        public bool $promoted = false;

        public function __construct(public ?Company $active, public bool $isOwner, public bool $hasFallback) {}

        public function getActiveCompany(): ?Company
        {
            return $this->active;
        }

        public function isOwnerOfActiveCompany(): bool
        {
            return $this->active !== null && $this->isOwner;
        }

        public function setNextAvailableCompany(): bool
        {
            $this->promoted = true;
            $this->active = null;

            return $this->hasFallback;
        }
    };
}

function archiveRequest(object $user): Request
{
    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->headers->set('Accept', 'application/json'); // take the 403 branch

    return $request;
}

it('lets the OWNER through when their active company is archived', function () {
    $company = (new Company)->forceFill(['company_archived_at' => now()]);
    $request = archiveRequest(archiveUser($company, isOwner: true));

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('denies a NON-owner member whose only company is archived', function () {
    $company = (new Company)->forceFill(['company_archived_at' => now()]);
    $request = archiveRequest(archiveUser($company, isOwner: false, hasFallback: false));

    $reached = false;
    expect(fn () => (new EnsureActiveTenant)->handle($request, function () use (&$reached) {
        $reached = true;

        return new Response('ok');
    }))->toThrow(HttpException::class);

    expect($reached)->toBeFalse();
});

it('self-heals a NON-owner member onto another available company', function () {
    $company = (new Company)->forceFill(['company_archived_at' => now()]);
    $user = archiveUser($company, isOwner: false, hasFallback: true);
    $request = archiveRequest($user);

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($user->promoted)->toBeTrue()
        ->and($response->isRedirect())->toBeTrue();
});

it('lets a member through when their active company is not archived', function () {
    $company = (new Company)->forceFill(['company_archived_at' => null]);
    $request = archiveRequest(archiveUser($company, isOwner: false));

    $response = (new EnsureActiveTenant)->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});
