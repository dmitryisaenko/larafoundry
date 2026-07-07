<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Owner-driven archive / unarchive of a company (phase 7 host request).
 *
 * Access mirrors {@see SwitchCompanyController}'s IDOR posture: the target is
 * looked up through the user's own `ownedCompanies()` relation, so only an OWNER
 * of that specific company can flip its archived state. A uuid the user does not
 * own — including a company they merely belong to as a member — is a 403, never a
 * silent no-op. This is the single write path for `company_archived_at`; the
 * column is not $fillable, so it is set server-side via forceFill here.
 *
 * Archiving is intentionally allowed from the CURRENTLY active company: the owner
 * archives the company they are working in, stays switched into it (they keep
 * access — see Company::isArchived), and the host shows the archived state. Only
 * non-owner members are turned away at the tenancy boundary.
 */
class ArchiveCompanyController extends Controller
{
    public function archive(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->ownedCompanyOrFail($request, $uuid);

        if (! $company->isArchived()) {
            $company->forceFill(['company_archived_at' => now()])->save();
        }

        return back()->with('status', __('larafoundry::tenancy.company_archived_done'));
    }

    public function unarchive(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->ownedCompanyOrFail($request, $uuid);

        if ($company->isArchived()) {
            $company->forceFill(['company_archived_at' => null])->save();
        }

        return back()->with('status', __('larafoundry::tenancy.company_unarchived'));
    }

    /**
     * Resolve a company the current user OWNS by uuid, or abort 403.
     *
     * Goes through ownedCompanies() (is_owner pivot) rather than a bare
     * Company::where('uuid'): membership alone is not enough to archive, and a
     * foreign uuid must never resolve. Returns the base Company so forceFill is
     * available regardless of the host's subclass.
     */
    protected function ownedCompanyOrFail(Request $request, string $uuid): Company
    {
        $user = $request->user();

        /** @var Company|null $company */
        $company = $user?->ownedCompanies()->where('uuid', $uuid)->first();

        if ($company === null) {
            abort(403);
        }

        return $company;
    }
}
