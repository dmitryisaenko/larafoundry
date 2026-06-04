<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

use Dmitryisaenko\LaraFoundry\Billing\Support\ArrayPlanRepository;

/**
 * The source of {@see PlanContract} objects (phase 3.1, decision D3.1-b).
 *
 * This is the seam that lets the core stay storage-agnostic about plans. The
 * FREE default — {@see ArrayPlanRepository}
 * — returns no plans (billing is off out of the box), so `find()` yields null
 * and nothing breaks. The paid add-on rebinds this to a config-, database-, or
 * gateway-backed repository.
 *
 * Callers depend on THIS contract, never on a concrete store, so where plans
 * live is one binding — the same pattern as TenantResolver (phase 1.2).
 */
interface PlanRepositoryContract
{
    /**
     * Every available plan.
     *
     * @return list<PlanContract>
     */
    public function all(): array;

    /**
     * The plan with the given id (as stored in `Company.plan_id`), or null when
     * none matches — e.g. a company on a plan that was retired, or the default
     * empty core repository. Callers must treat null as "no plan" (fail-closed),
     * never assume a plan exists.
     */
    public function find(string $planId): ?PlanContract;
}
