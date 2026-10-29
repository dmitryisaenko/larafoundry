<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creating an affiliate from the console (Ф4.4) — the `admin` eligibility path,
 * where a super-admin enrols a partner for a chosen user. `user_id` is unique against the
 * affiliates table so a user cannot hold two partner identities; the override rate is in
 * basis points.
 */
class StoreAffiliateRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'min:1', Rule::unique('affiliates', 'user_id')],
            'commission_rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['nullable', 'string', 'in:active,disabled,pending'],
        ];
    }

    public function statusOrDefault(): string
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : Affiliate::STATUS_ACTIVE;
    }
}
