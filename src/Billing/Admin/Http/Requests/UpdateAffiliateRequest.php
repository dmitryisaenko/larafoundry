<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates editing an affiliate from the console (Ф4.4): the per-affiliate override rate
 * (basis points; null clears it) and/or the status. A partial update — only the keys the
 * form sends are validated and applied, so the controller reads them via array_key_exists.
 */
class UpdateAffiliateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(VisitorStatus::class)->isAdmin($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commission_rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['nullable', 'string', 'in:active,disabled,pending'],
        ];
    }
}
