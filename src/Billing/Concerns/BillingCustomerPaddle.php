<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Concerns;

use Laravel\Paddle\Billable;

/**
 * The trait a host mixes into a SECOND Company subclass to make it a Paddle-payable
 * customer (sub-phase B4, decision D-B4-1).
 *
 * Why a second subclass and a second trait, rather than one Company that talks to
 * both gateways: Cashier (Stripe) and Cashier-Paddle each ship a trait literally
 * named `Billable` whose methods collide (`subscription()`, `subscribed()`, the
 * `subscriptions()` relation, …). A single PHP class therefore cannot `use` both.
 * So a host that routes some tenants to Paddle declares:
 *
 *     // Stripe-payable (the default `billable_model`)
 *     class Company extends \Dmitryisaenko\LaraFoundry\Tenancy\Models\Company
 *     {
 *         use \Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
 *     }
 *
 *     // Paddle-payable (the `paddle_billable_model`)
 *     class PaddleCompany extends \Dmitryisaenko\LaraFoundry\Tenancy\Models\Company
 *     {
 *         use \Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomerPaddle;
 *
 *         protected $table = 'companies';
 *     }
 *
 * and points `larafoundry-billing.paddle_billable_model` at the second one. Both
 * subclasses are views of the SAME `companies` row; a given tenant uses exactly one
 * depending on which gateway its region routes it to, so the two Billable surfaces
 * never load the same subscription at once.
 *
 * Like {@see BillingCustomer}, this is a thin re-export of Cashier-Paddle's
 * {@see Billable} — one named seam the host references instead of importing Cashier
 * directly, leaving room for Paddle-specific customer helpers later without the host
 * touching its model again.
 *
 * Cannot be combined with {@see BillingCustomer} on the same class (trait collision).
 */
trait BillingCustomerPaddle
{
    use Billable;
}
