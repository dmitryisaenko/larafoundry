<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Support\UserTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Adapts a paying subject that does not itself implement {@see Tenant} — typically the
 * host's User in `personal` mode — into one, WITHOUT losing the model behind it.
 *
 * The core ships {@see UserTenant} for the same job, but it is a bare key holder: the
 * billing seams also read columns off the tenant (`plan_id` for entitlements,
 * `country` for region routing), and a key-only adapter would silently answer "no
 * plan" — which fails closed on a customer who has actually paid. This decorator
 * forwards attribute reads to the wrapped model, so the seams behave exactly as they
 * do for a Company.
 *
 * A host that declares `implements Tenant` on its User skips this entirely: the model
 * is then passed straight through by {@see BillingSubject::tenant()} and every reader
 * that checks `instanceof Model` (region context in the free core) sees a real model.
 */
final class SubjectTenant implements Tenant
{
    public function __construct(public readonly Model $subject) {}

    public function getTenantKey(): int|string
    {
        return $this->subject->getKey();
    }

    /**
     * The tenant column readers (`LaraFoundryBilling::planFor()`, the entitlement
     * resolver) probe for this method before calling it — forwarding keeps them
     * reading the payer's real columns.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->subject->getAttribute($key);
    }

    public function __get(string $key): mixed
    {
        return $this->subject->getAttribute($key);
    }

    /**
     * Unwrap a tenant back to its Eloquent model when there is one — a Company (or any
     * host model implementing Tenant) is already the model, a decorated subject carries
     * it, and a key-only tenant has none.
     */
    public static function model(Tenant $tenant): ?Model
    {
        if ($tenant instanceof self) {
            return $tenant->subject;
        }

        return $tenant instanceof Model ? $tenant : null;
    }
}
