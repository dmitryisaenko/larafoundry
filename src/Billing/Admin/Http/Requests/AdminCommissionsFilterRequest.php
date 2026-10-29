<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the super-admin payout-report query (Ф4.4) — both the index and the CSV
 * export read it. Authorization is the canonical super-admin check; sort keys are
 * whitelisted.
 */
class AdminCommissionsFilterRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:pending,approved,paid,void'],
            'affiliate_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'max:3'],
            'period' => ['nullable', 'string', 'in:this_month,last_month,year,all,range'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort_by' => ['nullable', 'string', 'in:created_at,amount,id'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
