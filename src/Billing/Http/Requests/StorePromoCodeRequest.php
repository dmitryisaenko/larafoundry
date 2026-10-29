<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Requests;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validate a new promo code from the admin backend (sub-phase B5, decision D-B5-4
 * — backend now, Vue UI in B7). Ported from the donor's `StorePromoCodeRequest`.
 *
 * Authorization is a HOST-PLUGGABLE seam, not the donor's hardcoded
 * `GetVisitorStatusAction` (the add-on does not know the host's admin model). The
 * host registers a `PromoCode::MANAGE_ABILITY` gate that decides who may manage
 * codes; if it registers none, this fails CLOSED (deny) rather than open.
 */
class StorePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Fail closed: with no gate defined, nobody is authorised.
        if (! Gate::has(PromoCode::MANAGE_ABILITY)) {
            return false;
        }

        return Gate::allows(PromoCode::MANAGE_ABILITY);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('promo_codes', 'code'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in([PromoCode::KIND_DISCOUNT, PromoCode::KIND_FREE_MONTH])],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            // Whole units: a percentage is 0–100, a fixed amount is minor units.
            // The percentage ceiling guards against a >100% discount; the gateway
            // applies the real discount, this value only previews it.
            'discount_value' => [
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($this->effectiveDiscountType() === 'percentage' && (int) $value > 100) {
                        $fail('A percentage discount cannot exceed 100.');
                    }
                },
            ],
            'stripe_coupon_id' => ['nullable', 'string', 'max:255'],
            'paddle_discount_id' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'single_use_per_user' => ['boolean'],
            'is_active' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            // Personal-code owner id, optional. Plain integer — the add-on does not
            // know the host's users table to constrain against.
            'user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The discount type the percentage ceiling must be checked against.
     *
     * On a partial update that omits `discount_type` (changing only
     * `discount_value`), fall back to the persisted row's type so a `>100`
     * percentage cannot slip past on a code that is already a percentage. On store
     * there is no persisted row, so it is the submitted value.
     */
    protected function effectiveDiscountType(): ?string
    {
        $submitted = $this->input('discount_type');

        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $promoCode = $this->route('promo_code');

        return $promoCode instanceof PromoCode ? $promoCode->discount_type : null;
    }
}
