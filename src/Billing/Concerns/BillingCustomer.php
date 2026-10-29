<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Concerns;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Laravel\Cashier\Billable;

/**
 * The trait a host mixes into its OWN Company subclass to make it a payable
 * customer (build node 1, memory `billing-addon-decisions`).
 *
 * The add-on never applies a Billable trait to the core
 * {@see Company} — the core does not own
 * Cashier and must stay gateway-agnostic so the FREE package never depends on
 * Stripe. Instead the host does:
 *
 *     class Company extends \Dmitryisaenko\LaraFoundry\Tenancy\Models\Company
 *     {
 *         use \Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
 *     }
 *
 * and points both `larafoundry.tenancy.company_model` and
 * `larafoundry-billing.billable_model` at that subclass.
 *
 * Today this is a thin re-export of Cashier's {@see Billable} — one named seam the
 * host references instead of importing Cashier directly, so add-on-specific
 * customer helpers (e.g. resolving the Stripe price from a plan id, region-aware
 * currency on checkout) can be added here in later sub-phases without the host
 * touching its model again.
 */
trait BillingCustomer
{
    use Billable;

    /**
     * Cashier's identity columns, never mass-assignable.
     *
     * Same reasoning as {@see BillingSubscriber}: in `personal` mode these live on
     * `users`, where `$guarded = []` is a common host default — and a payer who can post
     * their own `stripe_id` can point their subscription at somebody else's Stripe
     * customer. Eloquent runs this initializer from the constructor; Cashier itself
     * assigns the column directly (never by mass assignment), so nothing internal breaks.
     *
     * @var list<string>
     */
    protected static array $billingCustomerGuarded = [
        'stripe_id',
        'pm_type',
        'pm_last_four',
    ];

    public function initializeBillingCustomer(): void
    {
        $this->mergeGuarded(self::$billingCustomerGuarded);
    }
}
