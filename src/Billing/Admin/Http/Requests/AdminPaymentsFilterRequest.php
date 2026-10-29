<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the super-admin payments-console query (sub-phase B7b).
 *
 * Authorization is the canonical super-admin check ({@see VisitorStatus::isAdmin}),
 * the second lock after the `larafoundry.admin` route gate — replacing the donor's
 * host-specific `GetVisitorStatusAction`. The whitelisted `sort_by`/`sort_order` keep
 * the controller's `orderBy()` free of attacker input.
 */
class AdminPaymentsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(VisitorStatus::class)->isAdmin($this->user());
    }

    /**
     * Smart default period: with no explicit `period`, show ALL payments when filtering
     * by a promo code (a code's history spans months) else the current month. Merged in
     * before validation so the filter's `period()` always receives a value (the core
     * Filter only dispatches keys present in the request).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('period')) {
            $this->merge([
                'period' => $this->filled('promo_code_id') ? 'all' : 'this_month',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'in:this_month,last_month,year,all,range'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'country' => ['nullable', 'string', 'max:2'],
            'payment_status' => ['nullable', 'string', 'in:success,failed'],
            'plan_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'promo_code_id' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', 'in:paid_at,created_at,amount,id'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
