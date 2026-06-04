<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Concerns;

use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Company/tenancy behaviour for a user model (phase 1.2).
 *
 * Composed alongside {@see IsLaraFoundryUser}
 * per the trait-slot idiom (decision D4/T6): identity stays in the auth trait,
 * tenancy lives here, RBAC arrives as its own trait in phase 1.3. The host model
 * does `use IsLaraFoundryUser, BelongsToTenancy;`.
 *
 * "Active company" is never read from the session directly — every call routes
 * through the {@see TenantResolver} so phase 6 can swap session storage for an
 * API token without touching this trait (decision T2).
 *
 * @mixin Model
 */
trait BelongsToTenancy
{
    /**
     * Companies the user belongs to (excluding soft-removed memberships and
     * soft-deleted companies).
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany($this->companyModel(), 'company_user')
            ->withPivot(Company::PIVOT_COLUMNS)
            ->withTimestamps()
            ->wherePivot('is_deleted', false);
    }

    /**
     * Companies where the user is an owner.
     */
    public function ownedCompanies(): BelongsToMany
    {
        return $this->companies()->wherePivot('is_owner', true);
    }

    /**
     * Companies where the user is a non-owner member.
     */
    public function employeeCompanies(): BelongsToMany
    {
        return $this->companies()->wherePivot('is_owner', false);
    }

    /**
     * The company currently active for this user/device, or null.
     */
    public function getActiveCompany(): ?Company
    {
        /** @var Company|null $tenant */
        $tenant = $this->tenantResolver()->current($this);

        return $tenant instanceof Company ? $tenant : null;
    }

    /**
     * Make a company the active one for this user/device.
     */
    public function setActiveCompany(Company|int|null $company): void
    {
        $this->tenantResolver()->setCurrent($this, $company);
    }

    /**
     * Clear the active company for this user/device.
     */
    public function clearActiveCompany(): void
    {
        $this->tenantResolver()->forget($this);
    }

    /**
     * Promote the next available company to active (owned first, then member).
     *
     * Skips blocked companies (phase 3.3): a company a super-admin has blocked is
     * not an eligible landing spot, so a multi-company member auto-lands on a
     * working one instead of being bounced to the blocked screen. Returns false
     * when the user has no UNBLOCKED company left — the caller then knows to send
     * them to the "create a company" flow (or, for a member whose only company is
     * blocked, the blocked screen, with no active company set so it cannot loop).
     */
    public function setNextAvailableCompany(): bool
    {
        $this->clearActiveCompany();

        $next = $this->ownedCompanies()->whereNull('company_blocked_at')->first()
            ?? $this->employeeCompanies()->whereNull('company_blocked_at')->first();

        if ($next === null) {
            return false;
        }

        $this->setActiveCompany($next);

        return true;
    }

    /**
     * The active company's id, or null.
     */
    public function getCurrentCompanyId(): int|string|null
    {
        return $this->getActiveCompany()?->getKey();
    }

    /**
     * The active company's name, or null.
     */
    public function getCurrentCompanyName(): ?string
    {
        return $this->getActiveCompany()?->name;
    }

    /**
     * Whether the user owns the given company.
     */
    public function isOwnerOf(Company|int $company): bool
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $this->ownedCompanies()
            ->whereKey($companyId)
            ->exists();
    }

    /**
     * Whether the user owns the company that is currently active.
     */
    public function isOwnerOfActiveCompany(): bool
    {
        $active = $this->getActiveCompany();

        return $active !== null && $this->isOwnerOf($active);
    }

    /**
     * Read the saved default landing route for the active company membership.
     */
    public function getDefaultRouteForActiveCompany(): ?string
    {
        $active = $this->getActiveCompany();

        if ($active === null) {
            return null;
        }

        return $this->companies()
            ->whereKey($active->getKey())
            ->first()?->pivot?->default_route;
    }

    /**
     * Persist the default landing route for the active company membership.
     */
    public function setDefaultRouteForActiveCompany(?string $routeName): void
    {
        $active = $this->getActiveCompany();

        if ($active === null) {
            return;
        }

        $this->companies()->updateExistingPivot($active->getKey(), [
            'default_route' => $routeName,
        ]);
    }

    protected function tenantResolver(): TenantResolver
    {
        return app(TenantResolver::class);
    }

    /**
     * @return class-string<Company>
     */
    protected function companyModel(): string
    {
        /** @var class-string<Company> $model */
        $model = config('larafoundry.tenancy.company_model', Company::class);

        return $model;
    }
}
