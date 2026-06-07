<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Settings\Http\Requests\UpdateSettingRequest;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service settings: the user's own account settings and (for an authorised
 * member) the active company's settings (phase 5.1).
 *
 * App settings live in the operator console (Admin\SettingsController). Company
 * settings are guarded by the existing RBAC permissions `company.settings.view`
 * / `company.settings.update` (owners and super-admins bypass), and their scope
 * is ALWAYS the user's ACTIVE company resolved server-side — never an id from the
 * request (recon: tenant-scope from active company + gate, not from input). User
 * settings are implicitly scoped to the caller, so there is no cross-user write.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function account(Request $request): Response
    {
        return Inertia::render('Settings/Account', [
            'schema' => $this->settings->schemaForScope('user'),
            'values' => $this->settings->allForScope('user'),
        ]);
    }

    public function updateAccount(UpdateSettingRequest $request): RedirectResponse
    {
        // User scope is the caller themselves — scope id resolves from auth, so a
        // user can only ever write their own settings.
        $this->write($request, 'user', null);

        return back()->with('status', __('larafoundry::settings.saved'));
    }

    public function company(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->can($user, 'company.settings.view'), 403);

        return Inertia::render('Settings/Company', [
            'schema' => $this->settings->schemaForScope('company'),
            'values' => $this->settings->allForScope('company'),
            'canUpdate' => $this->can($user, 'company.settings.update'),
        ]);
    }

    public function updateCompany(UpdateSettingRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->can($user, 'company.settings.update'), 403);

        // Scope id is the ACTIVE company, resolved server-side — never the request.
        $companyId = method_exists($user, 'getCurrentCompanyId') ? $user->getCurrentCompanyId() : null;
        abort_if($companyId === null, 403);

        $this->write($request, 'company', $companyId);

        return back()->with('status', __('larafoundry::settings.saved'));
    }

    /**
     * Enforce the key belongs to this scope and is form-editable, then write it.
     */
    protected function write(UpdateSettingRequest $request, string $scope, int|string|null $scopeId): void
    {
        $key = (string) $request->input('key');
        $definition = $this->settings->definition($key);

        if ($definition === null
            || ($definition['scope'] ?? null) !== $scope
            || ! ($definition['form'] ?? true)) {
            throw ValidationException::withMessages([
                'key' => __('larafoundry::settings.invalid_key'),
            ]);
        }

        $this->settings->set($key, $request->input('value'), $scopeId);
    }

    /**
     * Whether the user holds a permission in their active company (owners and
     * super-admins bypass — the RBAC trait handles that).
     */
    protected function can(mixed $user, string $permission): bool
    {
        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        $activeCompany = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

        return $user->hasPermissionTo($permission, $activeCompany);
    }
}
