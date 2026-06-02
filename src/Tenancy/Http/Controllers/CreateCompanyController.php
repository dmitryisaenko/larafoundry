<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\InviteEmployeesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep1Request;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep2Request;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep3Request;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The 3-step company-creation wizard (teams mode, phase 1.2).
 *
 * Step 1 creates the company and makes the user the owner; steps 2 and 3 enrich
 * it (logo, invitations). There is NO step 4 — plan/payment is billing (phase 3,
 * decision T1/D1.2-b) and not part of the free core.
 *
 * Every mutating step re-resolves the active company from the user rather than
 * trusting an id from the request, so a user can only ever edit the company they
 * actually own and have active (no IDOR on the wizard).
 */
class CreateCompanyController extends Controller
{
    use ResolvesActiveCompany;

    public function create(Request $request): Response
    {
        // The step is carried in the query string (set by each step's redirect)
        // and clamped to a company the user actually owns, so a mid-wizard reload
        // resumes on the right step instead of silently dropping to step 1.
        $step = $this->resolveStep($request);

        return Inertia::render('Tenancy/CreateCompany', [
            'step' => $step,
        ]);
    }

    public function storeStep1(StoreCompanyStep1Request $request, CreateCompanyAction $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return redirect()
            ->route('tenancy.companies.create', ['step' => 2])
            ->with('status', __('larafoundry::tenancy.company_created'));
    }

    public function storeStep2(StoreCompanyStep2Request $request): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('company-logos', 'public');
            $data['logo_path'] = $path;
            $data['logo'] = basename($path);
        }

        if ($data !== []) {
            $company->update($data);
        }

        return redirect()->route('tenancy.companies.create', ['step' => 3]);
    }

    /**
     * The wizard step to render: the requested step, but never past step 1 until
     * the user actually owns an active company (so a stale ?step=3 link can't
     * skip creation).
     */
    protected function resolveStep(Request $request): int
    {
        $requested = (int) $request->integer('step', 1);
        $requested = max(1, min(3, $requested));

        $hasCompany = $request->user()?->getActiveCompany() !== null;

        return $hasCompany ? $requested : 1;
    }

    public function storeStep3(StoreCompanyStep3Request $request, InviteEmployeesAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $emails = $request->emails();

        if ($emails !== []) {
            $action->execute($company, $emails, $request->user()->getAuthIdentifier());
        }

        return redirect()
            ->to(LaraFoundryTenancy::homeUrl())
            ->with('status', __('larafoundry::tenancy.setup_complete'));
    }
}
