<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Requests;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Illuminate\Validation\Rule;

/**
 * Validate an edit to an existing promo code (sub-phase B5). Shares the store
 * rules; the only differences are that the unique `code` check ignores the row
 * being edited, and every field is `sometimes` so a partial update is allowed.
 */
class UpdatePromoCodeRequest extends StorePromoCodeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Ignore the current row when checking code uniqueness on update.
        $promoCode = $this->route('promo_code');
        $id = $promoCode instanceof PromoCode ? $promoCode->getKey() : $promoCode;

        $rules['code'] = [
            'sometimes',
            'required',
            'string',
            'max:50',
            'regex:/^[A-Z0-9_-]+$/',
            Rule::unique('promo_codes', 'code')->ignore($id),
        ];

        // Allow a partial update: keep each field's rules but make presence optional.
        foreach ($rules as $field => $fieldRules) {
            if ($field === 'code') {
                continue;
            }

            if (is_array($fieldRules) && ! in_array('sometimes', $fieldRules, true)) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }
}
