<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the super-admin promo-console query (sub-phase B7b).
 *
 * Authorization is the canonical super-admin check ({@see VisitorStatus::isAdmin}),
 * the second lock after the `larafoundry.admin` route gate. The whitelisted
 * `sort_by`/`sort_order` keep the controller's `orderBy()` free of attacker input.
 */
class AdminPromoCodesFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:active,inactive,expired,exhausted'],
            'type' => ['nullable', 'string', 'in:personal,general'],
            'sort_by' => ['nullable', 'string', 'in:created_at,code,used_count'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
