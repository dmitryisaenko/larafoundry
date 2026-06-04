<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;

/**
 * The FREE core's default plan source: empty (phase 3.1, decision D3.1-b).
 *
 * Billing is off out of the box, so the core knows no plans — `all()` is empty
 * and `find()` always returns null. That is deliberate, not a stub gap: a free
 * self-host has no tariffs, and `Company.plan_id` simply resolves to "no plan"
 * (fail-closed). The paid add-on rebinds {@see PlanRepositoryContract} to a
 * config-, database-, or gateway-backed repository.
 *
 * It is named Array (not Null) because the host CAN seed it from config without
 * the add-on: point `larafoundry.billing.plans` at plan definitions and the
 * add-on's richer repository — or a thin host one — reads them. The core ships it
 * empty to keep zero billing assumptions.
 */
class ArrayPlanRepository implements PlanRepositoryContract
{
    public function all(): array
    {
        return [];
    }

    public function find(string $planId): ?PlanContract
    {
        return null;
    }
}
