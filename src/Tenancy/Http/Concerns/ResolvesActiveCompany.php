<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\Request;

/**
 * Shared active-company resolution for tenancy controllers (teams mode).
 *
 * The active company is ALWAYS re-resolved from the authenticated user (via the
 * TenantResolver), never taken from request input, so a controller can only act
 * on the company the caller currently acts as — no IDOR via a supplied id.
 *
 * Lives in one place so the owner-guard (a security boundary) cannot drift
 * between controllers: every tenancy controller composes this trait instead of
 * copy-pasting the checks.
 */
trait ResolvesActiveCompany
{
    /**
     * The caller's active company (membership implied by it being active).
     */
    protected function activeCompany(Request $request): Company
    {
        $company = $request->user()?->getActiveCompany();

        abort_if($company === null, 403);

        return $company;
    }

    /**
     * The caller's active company, asserting they OWN it.
     */
    protected function ownedActiveCompany(Request $request): Company
    {
        $user = $request->user();
        $company = $user?->getActiveCompany();

        abort_if($company === null || ! $user->isOwnerOf($company), 403);

        return $company;
    }
}
