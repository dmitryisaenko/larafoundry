<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Events;

use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a ticket is opened — by the user or by the operator on the user's
 * behalf (phase 4.2). Registered in the activity-log event registry, so every
 * new ticket is audited; `getLogProperties()` enriches the entry.
 */
class TicketCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $user,
        public readonly Ticket $ticket,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'ticket_id' => $this->ticket->getKey(),
            'ticket_uuid' => $this->ticket->uuid,
            'title' => $this->ticket->title,
            'priority' => $this->ticket->priority,
            'categories' => $this->ticket->categories ? implode(', ', $this->ticket->categories) : null,
        ];
    }
}
