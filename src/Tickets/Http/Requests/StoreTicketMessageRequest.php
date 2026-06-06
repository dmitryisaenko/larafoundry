<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Requests;

use Dmitryisaenko\LaraFoundry\Tickets\Policies\TicketPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shapes a USER reply to a ticket (phase 4.2).
 *
 * Per-ticket authorization (ownership) is the controller's `authorize('reply')`
 * call against {@see TicketPolicy};
 * this only validates the message body. Capped length, rendered as text.
 */
class StoreTicketMessageRequest extends FormRequest
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
