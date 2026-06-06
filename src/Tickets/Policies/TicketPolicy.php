<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Policies;

use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Ownership authorization for the USER-facing ticket routes (phase 4.2).
 *
 * The operator never goes through this policy — the admin console sits behind
 * the `larafoundry.admin` super-admin gate and sees every ticket. This is the
 * customer side: you may only see and reply to your OWN tickets.
 *
 * Cleaned up from the donor `TicketPolicy`, which forbade VIEWING a resolved
 * ticket while still allowing a reply to it — contradictory (you could reply to
 * something you could not open). Here the owner may always view their ticket
 * regardless of status; hiding old resolved tickets is a LIST concern
 * (`scopeExcludeOldResolved`), not a view permission. A reply reopens it.
 *
 * @param  Model  $user  the host user model
 */
class TicketPolicy
{
    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    /**
     * Edit the ticket itself (title/message). Not wired to a user route in v1
     * (only the operator edits), but kept coherent: an owner could edit only an
     * open ticket.
     */
    public function update(Model $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket) && $ticket->isOpen();
    }

    /**
     * Post a reply. Allowed on any of the owner's tickets — replying to a
     * resolved one reopens it (handled in the controller).
     */
    public function reply(Model $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    /**
     * Users never delete or close tickets — that is the operator's call.
     */
    public function delete(Model $user, Ticket $ticket): bool
    {
        return false;
    }

    public function close(Model $user, Ticket $ticket): bool
    {
        return false;
    }

    protected function owns(Model $user, Ticket $ticket): bool
    {
        $ownerId = $ticket->user_id;
        $userId = $user->getKey();

        // Compare as strings so this holds for non-integer user keys too (a host
        // on UUID/ULID ids): an `(int)` cast would collapse every uuid to 0 and
        // make every user "own" every ticket.
        return $ownerId !== null && (string) $ownerId === (string) $userId;
    }
}
