<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the input to the public "check this promo code" endpoint (sub-phase B5).
 *
 * Open to any caller the host puts behind the route's middleware (the route is
 * rate-limited; the host may add `auth`). It validates only the code shape — the
 * usability decision is the action's, and its outcome is deliberately a single
 * generic message so this endpoint reveals nothing about which codes exist.
 */
class ValidatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            // Optional minor-units base amount, only so the response can carry a
            // "you save X" estimate. Never used as the charged amount.
            'amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
