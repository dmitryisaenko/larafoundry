<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Events;

use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a message is posted to a ticket — by the user or the operator
 * (phase 4.2). Registered in the activity-log event registry so replies are
 * audited; `getLogProperties()` enriches the entry. The reply BODY is not
 * logged (it can carry personal data) — only that a reply happened and by whom.
 */
class TicketReplied
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $user,
        public readonly Ticket $ticket,
        public readonly bool $byOperator = false,
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
            'by_operator' => $this->byOperator,
        ];
    }
}
