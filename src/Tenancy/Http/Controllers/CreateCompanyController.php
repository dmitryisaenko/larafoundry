<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Authorization\Actions\CloneCompanyRolesAction;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\InviteEmployeesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep1Request;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep2Request;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\StoreCompanyStep3Request;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
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
        $company = $request->user()?->getActiveCompany();

        return Inertia::render('Tenancy/CreateCompany', [
            'step' => $step,
            // The company's assignable roles for the step-3 role-at-invite dropdown
            // (empty until the company exists). See assignableRoles() for why the
            // ensure runs here.
            'roles' => $company !== null ? $this->assignableRoles($company) : [],
        ]);
    }

    /**
     * The active company's assignable roles (id + name), ensuring they exist first.
     *
     * Roles are cloned asynchronously on company creation, but the step-3 dropdown
     * needs them NOW. The clone runs on the database+cron queue, which may not have
     * ticked yet for a user who clicked through to step 3 quickly — so we ensure
     * inline (idempotent + locked) right where the roles are needed. A normal user
     * finds them already cloned and this is a fast no-op; the async job remains and
     * keeps step 1 fast. Correctness does not depend on the cron timing.
     *
     * Only company-scoped roles are returned (NOT global/template roles), so the
     * dropdown can only offer roles that are valid to assign in this company.
     *
     * @return Collection<int, Role>
     */
    protected function assignableRoles(Company $company)
    {
        app(CloneCompanyRolesAction::class)->execute($company);

        return Role::query()
            ->where('company_id', $company->getKey())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function storeStep1(StoreCompanyStep1Request $request, CreateCompanyAction $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return redirect()
            ->route('tenancy.companies.create', ['step' => 2])
            ->with('status', __('larafoundry::tenancy.company_created'));
    }

    public function storeStep2(StoreCompanyStep2Request $request, StoreUploadedFileAction $storeFile): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            // Phase 2.4: store through the media action (configured disk, generated
            // filename, FileUploaded event) rather than a hardcoded public disk, so
            // logos follow `larafoundry-media.disk` and are portable to S3.
            $stored = $storeFile->execute(
                $request->file('logo'),
                context: 'company_logo',
                directory: (string) config('larafoundry-media.paths.company_logos', 'company-logos'),
            );

            $data['logo_path'] = $stored->path;
            $data['logo'] = $stored->filename;
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

        $invitations = $request->invitations();

        if ($invitations !== []) {
            $action->execute($company, $invitations, $request->user()->getAuthIdentifier());
        }

        return redirect()
            ->to(LaraFoundryTenancy::homeUrl())
            ->with('status', __('larafoundry::tenancy.setup_complete'));
    }
}
