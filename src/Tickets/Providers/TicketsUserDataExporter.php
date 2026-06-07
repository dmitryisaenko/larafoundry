<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Models\TicketMessage;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Exports the user's own support tickets and their conversations (phase 5.3).
 *
 * The helpdesk module owns this section: it implements the profile export
 * contract and is added to the export registry from the package's bootTickets()
 * hook — the same additive idiom a host uses to add its own export (orders,
 * invoices…) without the export flow knowing every section.
 *
 * Only the user's OWN tickets are exported (the {@see Ticket} ownership relation).
 * Each message is labelled "you" or "support" by authorship, so the operator's
 * identity is never disclosed in the subject's export.
 */
class TicketsUserDataExporter implements ExportsUserDataProvider
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportFor(Authenticatable $user): array
    {
        if (! method_exists($user, 'tickets')) {
            return [];
        }

        $ownerId = (string) $user->getAuthIdentifier();

        return $user->tickets()
            ->with('messages')
            ->get()
            ->map(fn (Ticket $ticket) => [
                'reference' => $ticket->uuid,
                'title' => $ticket->title,
                'message' => $ticket->message,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'categories' => $ticket->categories,
                'labels' => $ticket->labels,
                'opened_at' => optional($ticket->created_at)->toIso8601String(),
                'last_activity_at' => optional($ticket->updated_at)->toIso8601String(),
                'messages' => $ticket->messages
                    ->map(fn (TicketMessage $message) => [
                        'from' => (string) $message->user_id === $ownerId ? 'you' : 'support',
                        'message' => $message->message,
                        'sent_at' => optional($message->created_at)->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
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
