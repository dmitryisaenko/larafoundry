<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Concerns;

use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a `personal`-mode payer's model answer the access question — the User-side
 * counterpart of what the core {@see Company} already carries.
 *
 * In `teams` mode the paying subject is a Company, and the free core gives it both
 * {@see HasSubscription} and `hasAccess()`. In `personal` mode the subject is the
 * host's User, which the core leaves alone (it stays gateway-agnostic and knows
 * nothing about a paid add-on). A host makes its User a payer with two mix-ins:
 *
 *     class User extends Authenticatable
 *     {
 *         use \Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;    // Cashier identity
 *         use \Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingSubscriber; // access state
 *     }
 *
 * and points `larafoundry-billing.billable_model` at it. The subscription columns
 * themselves come from the add-on's migration (it adds the core's four billing
 * columns to any billable table that is not `companies`).
 *
 * Deliberately NOT provided here:
 *  - `isInSetup()` — a company earns a grace period while it is still being filled
 *    in; a person signing up has no such phase, and defining it would silently
 *    exempt every user from the paywall. The block-status action treats a subject
 *    without the method as "setup finished", which is the right default.
 *  - date casts — {@see SubscriptionState} reads the columns through `getAttribute`
 *    and parses raw strings, so a host may add `datetime` casts for its own
 *    convenience but nothing here depends on them.
 *
 * @mixin Model
 */
trait BillingSubscriber
{
    use HasSubscription;

    /**
     * Columns that decide whether this payer has paid. NEVER mass-assignable.
     *
     * On `companies` the core provides this protection structurally — it keeps the
     * billing columns out of `$fillable`, which is why even the add-on writes them with
     * `forceFill()`. On `users` there is no such guarantee: `$guarded = []` is a common
     * host default, and a plain `$user->update($request->validated())` in a profile
     * controller would then let anyone post `plan_id` / `subscription_ends_at` and grant
     * themselves a free, endless subscription (or clear `free_month_used_at` to re-take
     * the trial). Eloquent runs this initializer from the constructor, so the guard is in
     * place for every instance — a host that deliberately wants one of these fillable
     * still wins by listing it in its own `$fillable`.
     *
     * @var list<string>
     */
    protected static array $billingSubscriberGuarded = [
        'plan_id',
        'billing_period',
        'trial_ends_at',
        'subscription_ends_at',
        'free_month_used_at',
    ];

    public function initializeBillingSubscriber(): void
    {
        $this->mergeGuarded(self::$billingSubscriberGuarded);
    }

    /**
     * Whether this payer may use the app right now.
     *
     * Mirrors {@see Company::hasAccess()} exactly, including the FREE promise: with
     * `larafoundry.billing.enabled` off nothing is gated. With billing on it reads the
     * mirrored columns — a live trial OR an active subscription grants access, anything
     * else denies (fail-closed).
     */
    public function hasAccess(): bool
    {
        if (! LaraFoundryBilling::enabled()) {
            return true;
        }

        return $this->subscriptionState()->hasAccess();
    }
}
