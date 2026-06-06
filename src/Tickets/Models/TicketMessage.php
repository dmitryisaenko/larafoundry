<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a ticket conversation (phase 4.2).
 *
 * Authored either by the ticket owner or by the platform operator; the author's
 * `isAdmin()` flag (read in the resources) tells the frontend which side of the
 * thread a message belongs to. Lives in `larafoundry_ticket_messages`.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketMessage extends Model
{
    protected $table = 'larafoundry_ticket_messages';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
    ];

    /**
     * Posting a message always bumps the parent ticket's `updated_at` — so the
     * workflow ordering and the "new / unanswered" flag (created_at == updated_at)
     * track the real last activity, even when the reply does not change the
     * status (Eloquent's update() skips the timestamp when nothing is dirty).
     *
     * @var array<int, string>
     */
    protected $touches = ['ticket'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The message author.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModel());
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model;
    }
}
