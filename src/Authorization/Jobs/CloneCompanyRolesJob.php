<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Jobs;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Clone the catalog's role templates into a freshly created company (phase 1.3).
 *
 * Dispatched from the CompanyCreated listener (decision D1.3-c). Queued because
 * cloning is not on the owner's critical path: the owner already has full access
 * via the `is_owner` flag bypass, so a brief window before the cloned roles exist
 * only affects employees who are invited LATER — by which time the job has run.
 *
 * Idempotent: if the company already has any role, the job no-ops, so a retried or
 * duplicated dispatch never produces duplicate roles. The clone runs in one
 * transaction so a company is never left with a half-cloned role set.
 */
class CloneCompanyRolesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company) {}

    public function handle(): void
    {
        if (Role::where('company_id', $this->company->getKey())->exists()) {
            return;
        }

        $templates = Role::query()
            ->where('is_template', true)
            ->where('is_global', false)
            ->with('permissions:id')
            ->get();

        if ($templates->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($templates) {
            foreach ($templates as $template) {
                $companyRole = Role::create([
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'description' => $template->description,
                    'is_global' => false,
                    'is_template' => false,
                    'is_custom' => false,
                    'company_id' => $this->company->getKey(),
                    'created_by_id' => $this->company->created_by_id,
                ]);

                $companyRole->permissions()->sync($template->permissions->pluck('id'));
            }
        });
    }
}
