<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one commission for the super-admin payout report (Ф4.4). Money stays in minor
 * units (the Vue formats it); there is no currency conversion — the controller reports
 * totals per currency. The affiliate code/email come from the eager-loaded relation.
 *
 * @mixin AffiliateCommission
 */
class AdminCommissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AffiliateCommission $commission */
        $commission = $this->resource;
        $affiliate = $commission->affiliate;
        $user = is_object($affiliate) ? $affiliate->user : null;

        return [
            'id' => $commission->getKey(),
            'affiliate_id' => $commission->affiliate_id,
            'affiliate_code' => is_object($affiliate) ? $affiliate->code : null,
            'affiliate_email' => is_object($user) ? ($user->email ?? null) : null,
            'company_id' => $commission->company_id,
            'currency' => $commission->currency,
            'amount' => $commission->amount,
            'status' => $commission->status,
            'basis' => $commission->basis,
            'rate_bp' => $commission->rate_bp,
            'created_at' => $commission->created_at?->format('Y-m-d H:i:s'),
            'approved_at' => $commission->approved_at?->format('Y-m-d H:i:s'),
            'paid_at' => $commission->paid_at?->format('Y-m-d H:i:s'),
            'payout_reference' => $commission->payout_reference,
        ];
    }
}
