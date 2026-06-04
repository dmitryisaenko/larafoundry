<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one company for the super-admin console (phase 3.3).
 *
 * The subscription block is READ-ONLY and computed from the billing columns the
 * core owns (phase 3.1): it reports what state a company is in, never offers to
 * change it — managing a plan/period is the paid add-on's job. With billing
 * disabled (the FREE default) every company reads as `never_activated` with
 * `has_access: true`; that is honest, not a bug — the screen shows "billing is
 * not wired here", and `has_access` reflects the real gate (`Company::hasAccess`).
 *
 * The donor list carried per-company payment sums and last-payment rows (recon
 * §E); those are deliberately absent — payment records live in the add-on, so
 * the core never shows money it does not store. The owner is summarised to
 * identity + contact (no social profiling, recon finding #6).
 *
 * @property Model $resource
 */
class AdminCompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'logo_url' => $this->logo_url,
            'owner' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'lastname' => $this->createdBy?->lastname,
                'email' => $this->createdBy?->email,
            ]),
            'employees_count' => $this->whenCounted('users'),
            'is_blocked' => $this->company_blocked_at !== null,
            'blocked_reason' => $this->company_blocked_reason,
            'subscription' => [
                'plan_id' => $this->plan_id,
                'billing_period' => $this->billing_period,
                'trial_ends_at' => $this->trial_ends_at?->toISOString(),
                'subscription_ends_at' => $this->subscription_ends_at?->toISOString(),
                'status' => SubscriptionStatus::for($this->resource),
                'has_access' => $this->resource->hasAccess(),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'created_date' => $this->created_at?->format('d.m.Y'),
        ];
    }
}
