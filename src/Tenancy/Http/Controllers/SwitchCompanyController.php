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

        $user->setActiveCompany($company);

        return back()->with('status', __('larafoundry::tenancy.company_switched', [
            'company' => $company->name,
        ]));
    }
}
