<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Models\TicketMessage;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Erases the user's support tickets on account deletion (phase 5.3).
 *
 * The helpdesk module owns this section: it implements the purge contract and is
 * added to the purge registry from bootTickets() — the symmetric mirror of its
 * export provider. The user's tickets are their own personal data, so they are
 * DELETED outright (decision D-5.3-9), conversation and all.
 *
 * Messages are deleted explicitly by ticket id rather than relying on the
 * database FK cascade, so the erasure is correct on every driver (SQLite does
 * not enforce cascades unless foreign keys are pragma-enabled). A customer only
 * ever authors messages on their OWN tickets (the helpdesk is customer↔operator),
 * so deleting their tickets removes all of their authored content.
 */
class TicketsUserDataPurger implements PurgesUserData
{
    public function purgeFor(Authenticatable $user): void
    {
        if (! method_exists($user, 'tickets')) {
            return;
        }

        $ticketIds = $user->tickets()->pluck('id');

        if ($ticketIds->isEmpty()) {
            return;
        }

        TicketMessage::query()->whereIn('ticket_id', $ticketIds)->delete();
        Ticket::query()->whereKey($ticketIds->all())->delete();
    }

    public function key(): string
    {
        return 'tickets';
    }

    public function priority(): int
    {
        return 40;
    }
}
