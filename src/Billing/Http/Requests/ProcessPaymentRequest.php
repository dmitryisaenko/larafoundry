<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Requests;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a checkout submission (sub-phase B7a).
 *
 * `plan_id` is constrained to the repository's known plan ids (not a free string), so
 * a tampered id is rejected before it reaches the gateway. The gateway, not this
 * request, owns the charged amount — there is no price field here to forge.
 */
final class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', Rule::in($this->availablePlanIds())],
            'billing_period' => ['required', 'string', Rule::in(['monthly', 'yearly'])],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'use_free_month' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function availablePlanIds(): array
    {
        return array_values(array_map(
            static fn (PlanContract $plan): string => $plan->id(),
            app(PlanRepositoryContract::class)->all(),
        ));
    }
}
