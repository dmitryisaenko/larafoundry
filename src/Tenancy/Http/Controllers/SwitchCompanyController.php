<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Switches the user's active company (the switcher in the UI).
 *
 * Membership is enforced through the user's own `companies()` relation: the
 * target is looked up via `companies()->find()`, so a user can only switch to a
 * company they actually belong to. An unknown/foreign uuid is a 403, never a
 * silent switch — closing the obvious IDOR on company selection.
 *
 * A blocked company (phase 3.3) is refused up front rather than letting the user
 * switch in and immediately bounce off the tenancy boundary: switching into a
 * company they cannot use is a dead end, so we reject it with a clear message and
 * leave their current active company untouched.
 *
 * An archived company (phase 7) is refused the same way, but only for NON-owner
 * members — the owner is allowed to switch in so they can unarchive it.
 */
class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, string $uuid): RedirectResponse
    {
        $user = $request->user();

        /** @var Company|null $company */
        $company = $user?->companies()->where('uuid', $uuid)->first();

        if ($company === null) {
            abort(403);
        }

        if (method_exists($company, 'isBlocked') && $company->isBlocked()) {
            return back()->with('error', __('larafoundry::tenancy.company_blocked'));
        }

        // An archived company (phase 7) is owner-only: the owner may switch in to
        // read it and unarchive it, but a non-owner member is refused up front —
        // same dead-end reasoning as the block, only narrower. Ownership is read
        // from the pivot loaded by companies() (withPivot is_owner).
        if (method_exists($company, 'isArchived') && $company->isArchived()
            && ! (bool) $company->pivot->is_owner) {
            return back()->with('error', __('larafoundry::tenancy.company_archived'));
        }

        $user->setActiveCompany($company);

        return back()->with('status', __('larafoundry::tenancy.company_switched', [
            'company' => $company->name,
        ]));
    }
}
