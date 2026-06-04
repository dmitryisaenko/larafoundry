<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Concerns;

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Illuminate\Database\Eloquent\Model;

/**
 * Subscription-state convenience for a billable model (phase 3.1).
 *
 * Applied to the core Company. Thin by design — it delegates every decision to
 * {@see SubscriptionState} so the access logic has exactly one home (the donor
 * scattered the same `->isFuture()` checks across several models). It does NOT
 * declare the columns' casts or fillable; the Company model and the billing
 * migration own the schema. The trait only exposes readable accessors over
 * whatever those columns hold.
 *
 * `hasAccess()` itself lives on Company (not here) because it also consults the
 * `billing.enabled` switch — a gate-level concern, not a per-column read.
 *
 * @mixin Model
 */
trait HasSubscription
{
    /**
     * Per-instance memo of the decoded state.
     *
     * The access gate (Company::hasAccess) is positioned to be as hot as RBAC's
     * permission check, which the core deliberately memoises (phase 1.3, the same
     * memo-property + explicit-reset idiom this trait follows). A single decode
     * per model instance collapses the repeated attribute reads / date parsing
     * when hasAccess, isOnTrial and hasActiveSubscription are all consulted in one
     * request. The model instance is the natural, short-lived cache key.
     */
    protected ?SubscriptionState $resolvedSubscriptionState = null;

    /**
     * The decoded subscription state for this model (memoised per instance).
     */
    public function subscriptionState(): SubscriptionState
    {
        return $this->resolvedSubscriptionState ??= SubscriptionState::for($this);
    }

    /**
     * Drop the memo so a later read re-decodes the columns — call after writing
     * new subscription state on the same instance (mirrors RBAC's
     * forgetResolvedPermissions).
     */
    public function forgetSubscriptionState(): static
    {
        $this->resolvedSubscriptionState = null;

        return $this;
    }

    /**
     * Whether a trial is currently running.
     */
    public function isOnTrial(): bool
    {
        return $this->subscriptionState()->isOnTrial();
    }

    /**
     * Whether a paid subscription is currently active.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscriptionState()->hasActiveSubscription();
    }
}
