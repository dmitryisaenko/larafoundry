<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shapes an OPERATOR editing a ticket (phase 4.2).
 *
 * Unlike the user side, the operator MAY set the status directly (the console
 * edit form), constrained to the configured workflow slugs. Priority, each
 * category and each label are constrained to their configured lists. A status
 * change is audited by the controller (security finding S3).
 */
class UpdateTicketRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'message' => ['sometimes', 'required', 'string', 'max:5000'],
            'priority' => ['sometimes', 'required', 'string', Rule::in($this->slugs('priorities'))],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->slugs('statuses'))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($this->slugs('categories'))],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['string', Rule::in($this->slugs('labels'))],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function slugs(string $key): array
    {
        return (array) config("larafoundry-tickets.{$key}", []);
    }
}
