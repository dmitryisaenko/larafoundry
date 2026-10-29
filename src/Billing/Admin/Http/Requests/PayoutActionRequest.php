<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a bulk payout action (Ф4.4): approve / mark-paid / void over a set of selected
 * commission ids. `reference` is the optional payout note carried on mark-paid. The state
 * transition itself is enforced in the controller (e.g. mark-paid only touches approved
 * rows), so a stale selection can never jump a commission across an illegal transition.
 */
class PayoutActionRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map('intval', (array) $this->validated()['ids'])));

        return $ids;
    }
}
