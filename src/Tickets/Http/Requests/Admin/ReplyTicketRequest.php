<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shapes an OPERATOR reply to a ticket (phase 4.2).
 *
 * Replaces the donor's inline `$request->validate(['message' => ...])` in the
 * admin controller with a proper FormRequest (consistent with the rest of the
 * module). Behind the `larafoundry.admin` gate.
 */
class ReplyTicketRequest extends FormRequest
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
            'message' => ['required', 'string', 'min:2', 'max:5000'],
        ];
    }
}
