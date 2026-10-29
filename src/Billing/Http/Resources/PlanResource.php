<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a {@see PlanContract} for the pricing/checkout pages (sub-phase B7a).
 *
 * Priced for ONE currency — the tenant's, resolved server-side via RegionContext and
 * passed in — so the page never lets the client pick its own currency. Prices are minor
 * units (cents) or null when the plan is not sold in that currency/period; `name` is an
 * English-as-key string the Vue layer renders with $t().
 */
final class PlanResource extends JsonResource
{
    public function __construct(PlanContract $resource, private readonly string $currency)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanContract $plan */
        $plan = $this->resource;

        return [
            'id' => $plan->id(),
            'name' => $plan->name(),
            'features' => $plan->features(),
            'currency' => $this->currency,
            'prices' => [
                'monthly' => $plan->priceFor('monthly', $this->currency),
                'yearly' => $plan->priceFor('yearly', $this->currency),
            ],
        ];
    }
}
