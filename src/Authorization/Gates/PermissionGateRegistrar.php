<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Gates;

use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Turns every catalog permission slug into a Gate ability (phase 1.3).
 *
 * After this runs, `$this->authorize('company.roles.view')` and
 * `Gate::allows('company.roles.view')` work everywhere with the same semantics as
 * the trait — the active company is resolved here, so call sites stay context-free
 * (decision D1.3: faithful to the donor's call-site contract).
 *
 * A single `Gate::before` short-circuits super-admins for ALL abilities (returning
 * null means "no opinion, fall through to the defined gate"). Defining a gate per
 * slug (rather than one catch-all) keeps Laravel's ability list introspectable.
 */
class PermissionGateRegistrar
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function register(): void
    {
        Gate::before(function (Authenticatable $user) {
            return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ? true : null;
        });

        foreach ($this->catalog->slugs() as $slug) {
            Gate::define($slug, function (Authenticatable $user) use ($slug): bool {
                if (! method_exists($user, 'hasPermissionTo')) {
                    return false;
                }

                $company = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

                return $user->hasPermissionTo($slug, $company);
            });
        }
    }
}
