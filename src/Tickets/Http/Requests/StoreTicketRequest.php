<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shapes a USER opening a ticket (phase 4.2).
 *
 * `status` is never accepted from input — it is a transition the controller
 * drives (a user-opened ticket starts `wait-moderator`). Priority and each
 * category are constrained to the configured slug lists, so a crafted value
 * cannot widen the set. Body length is capped; the frontend renders it as text
 * (no HTML execution), closing the donor's `v-html` XSS (finding S1).
 */
class StoreTicketRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::in($this->priorities())],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($this->categories())],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function priorities(): array
    {
        return (array) config('larafoundry-tickets.priorities', ['standard', 'high']);
    }

    /**
     * @return array<int, string>
     */
    protected function categories(): array
    {
        return (array) config('larafoundry-tickets.categories', []);
    }
}
