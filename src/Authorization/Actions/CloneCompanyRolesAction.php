<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Actions;

use Dmitryisaenko\LaraFoundry\Authorization\Jobs\CloneCompanyRolesJob;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clone the catalog's role templates into a company (phase 1.3, role-at-invite).
 *
 * Extracted from {@see CloneCompanyRolesJob}
 * so the same work can run BOTH asynchronously (the queued job on CompanyCreated,
 * keeping step 1 fast) and SYNCHRONOUSLY (the wizard ensures roles exist before
 * rendering the role-at-invite dropdown on step 3, since the database+cron queue
 * may not have ticked yet). Correctness no longer depends on the cron timing.
 *
 * Idempotent: no-ops when the company already has any role. Atomic across the two
 * paths via a per-company lock — the exists() check is repeated UNDER the lock so
 * two callers that both pass the fast-path check can't both insert (the unique
 * (company_id, slug) index is the final backstop). The clone runs in one
 * transaction so a company is never left with a half-cloned role set.
 */
class CloneCompanyRolesAction
{
    public function execute(Company $company): void
    {
        // Fast path outside the lock: the common case is a no-op (the async job
        // already cloned), so avoid paying for the lock when there is plainly
        // nothing to do.
        if ($this->companyHasRoles($company)) {
            return;
        }

        $clone = function () use ($company): void {
            // Re-check UNDER the lock: the sync ensure and the async job can both
            // pass the fast path before either inserts. The lock serialises them;
            // the second sees the first's rows and no-ops.
            if ($this->companyHasRoles($company)) {
                return;
            }

            $this->cloneTemplates($company);
        };

        // Use an atomic lock when the cache store supports one (array/file/redis/
        // database/…). A lock-less store (e.g. 'null') still gets the under-lock
        // recheck plus the unique index as backstops, so it never throws.
        if (Cache::getStore() instanceof LockProvider) {
            Cache::lock("larafoundry:clone-roles:{$company->getKey()}", 10)->block(5, $clone);

            return;
        }

        $clone();
    }

    protected function companyHasRoles(Company $company): bool
    {
        return Role::query()->where('company_id', $company->getKey())->exists();
    }

    protected function cloneTemplates(Company $company): void
    {
        $templates = Role::query()
            ->where('is_template', true)
            ->where('is_global', false)
            ->with('permissions:id')
            ->get();

        if ($templates->isEmpty()) {
            return;
        }

        try {
            DB::transaction(function () use ($templates, $company): void {
                foreach ($templates as $template) {
                    $companyRole = Role::create([
                        'name' => $template->name,
                        'slug' => $template->slug,
                        'description' => $template->description,
                        'is_global' => false,
                        'is_template' => false,
                        'is_custom' => false,
                        'company_id' => $company->getKey(),
                        'created_by_id' => $company->created_by_id,
                    ]);

                    $companyRole->permissions()->sync($template->permissions->pluck('id'));
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent caller cloned first and we collided on the unique
            // (company_id, slug) index — reachable only on a cache store WITHOUT
            // atomic locks (the lock serialises the two paths otherwise). The
            // index did its job; treat it as a benign no-op IF the roles now
            // exist, and rethrow only if something else is actually wrong.
            if (! $this->companyHasRoles($company)) {
                throw $e;
            }
        }
    }
}
