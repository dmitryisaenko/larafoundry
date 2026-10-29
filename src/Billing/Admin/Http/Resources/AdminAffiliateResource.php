<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one affiliate for the super-admin affiliates list (Ф4.4).
 *
 * The commission counts (pending/approved/paid) and referral count are loaded by the
 * controller via withCount, so the list never N+1s. The partner's display name is composed
 * from the host user's name/lastname (the core user has no getFullname()).
 *
 * @mixin Affiliate
 */
class AdminAffiliateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Affiliate $affiliate */
        $affiliate = $this->resource;
        $user = $affiliate->user;

        return [
            'id' => $affiliate->getKey(),
            'code' => $affiliate->code,
            'status' => $affiliate->status,
            'commission_rate_bp' => $affiliate->commission_rate_bp,
            'user_id' => $affiliate->user_id,
            'user_email' => is_object($user) ? ($user->email ?? null) : null,
            'user_fullname' => $this->fullname($user),
            'user_country' => is_object($user) ? ($user->country ?? null) : null,
            'referrals_count' => (int) ($affiliate->referrals_count ?? 0),
            'pending_count' => (int) ($affiliate->pending_count ?? 0),
            'approved_count' => (int) ($affiliate->approved_count ?? 0),
            'paid_count' => (int) ($affiliate->paid_count ?? 0),
            'created_at' => $affiliate->created_at?->format('Y-m-d'),
        ];
    }

    private function fullname(mixed $user): string
    {
        if (! is_object($user)) {
            return '';
        }

        return trim((string) ($user->name ?? '').' '.(string) ($user->lastname ?? ''));
    }
}
