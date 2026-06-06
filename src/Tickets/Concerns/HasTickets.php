<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Concerns;

use Dmitryisaenko\LaraFoundry\Notifications\Concerns\HasNotifications;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gives a host user model its support tickets (phase 4.2).
 *
 * Mirrors {@see HasNotifications}:
 * one `use` per the trait-slot idiom. The relation is the user's OWN tickets —
 * the operator reads everyone's tickets through the admin console, not this
 * relation.
 */
trait HasTickets
{
    /**
     * The tickets this user opened, newest first.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->latest();
    }
}
