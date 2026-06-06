<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a company and makes its creator the owner (wizard step 1).
 *
 * Wrapped in a transaction so a half-built tenant (company without its owner
 * membership) can never persist. After creation the company becomes the user's
 * active one and {@see CompanyCreated} fires — the seam phase 1.3 listens on to
 * clone default roles (decision D1.2-f). Roles are NOT created here.
 *
 * The platform super-admin is rejected up front (phase 1.4): the operator
 * identity is kept separate from tenant identities, so it can never own a
 * company. This is the server-side half of that separation; the other half
 * keeps the super-admin out of tenant routes (RedirectSuperAdminToConsole).
 */
class CreateCompanyAction
{
    /**
     * @param  array{name: string, country?: string|null, description?: string|null}  $data
     */
    public function execute(Authenticatable $owner, array $data): Company
    {
        // Reject the operator on either signal: the resolved admin status (flag +
        // email) OR the reserved email alone. The email check closes the gap
        // where a reserved-email account that never got the flag would otherwise
        // slip through isAdmin().
        $email = method_exists($owner, 'getAttribute') ? $owner->getAttribute('email') : null;

        abort_if(
            app(VisitorStatus::class)->isAdmin($owner)
                || VisitorStatus::isSuperAdminEmail(is_string($email) ? $email : null),
            403,
            __('larafoundry::tenancy.super_admin_cannot_own'),
        );

        $companyClass = config('larafoundry.tenancy.company_model', Company::class);

        $company = DB::transaction(function () use ($companyClass, $owner, $data): Company {
            /** @var Company $company */
            $company = $companyClass::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($companyClass, $data['name']),
                'country' => $data['country'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by_id' => $owner->getAuthIdentifier(),
            ]);

            $company->addEmployee($owner, addedById: $owner->getAuthIdentifier(), isOwner: true);

            return $company;
        });

        // Bind it as active for this device, then announce creation.
        if (method_exists($owner, 'setActiveCompany')) {
            $owner->setActiveCompany($company);
        }

        CompanyCreated::dispatch($company, $owner);

        return $company;
    }

    /**
     * A globally-unique slug derived from the name (decision D1.2-e).
     *
     * Checks against trashed rows too, so a soft-deleted company doesn't free up
     * a slug that would then collide on the unique index.
     *
     * @param  class-string<Company>  $companyClass
     */
    protected function uniqueSlug(string $companyClass, string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($companyClass, $slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Company>  $companyClass
     */
    protected function slugTaken(string $companyClass, string $slug): bool
    {
        return $companyClass::withTrashed()->where('slug', $slug)->exists();
    }
}
