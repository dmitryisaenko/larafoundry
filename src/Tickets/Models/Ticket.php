<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Models;

use Dmitryisaenko\LaraFoundry\Concerns\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A support ticket: the channel between a host user and the platform operator
 * (phase 4.2).
 *
 * Extracted from the donor `App\Models\Ticket` (recon §phase-4.2), which sat on
 * `coderflex/laravel-ticket`. That dependency is cut (decision D-4.2-1) — this
 * is a self-contained model. Categories and labels are JSON slug arrays driven
 * by `config('larafoundry-tickets')` (decision D-4.2-5), not pivot tables. The
 * dead `assigned_to` column and the donor's invalid-operator
 * `getUnderReviewTicketsCont()` (it used `'!=='`) are dropped.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $title
 * @property string $message
 * @property string $priority
 * @property string $status
 * @property array<int, string>|null $categories
 * @property array<int, string>|null $labels
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ticket extends Model
{
    use Filterable;

    public const STATUS_WAIT_MODERATOR = 'wait-moderator';

    public const STATUS_WAIT_CUSTOMER = 'wait-customer';

    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'larafoundry_tickets';

    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'message',
        'priority',
        'status',
        'categories',
        'labels',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'labels' => 'array',
        ];
    }

    /**
     * Assign a uuid on creation so user-facing URLs never expose the sequential
     * id (defense-in-depth on top of the ownership policy).
     */
    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            if (! $ticket->uuid) {
                $ticket->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The user who opened the ticket (its author).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModel());
    }

    /**
     * The conversation, oldest message first.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->oldest();
    }

    /**
     * Hide a resolved ticket from a listing once it has sat untouched for the
     * configured number of days. Open tickets are always kept.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeExcludeOldResolved(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('larafoundry-tickets.resolved_hidden_after_days', 7);

        return $query->where(function (Builder $q) use ($days) {
            $q->where('status', '!=', self::STATUS_RESOLVED)
                ->orWhere(function (Builder $sub) use ($days) {
                    $sub->where('status', self::STATUS_RESOLVED)
                        ->where('updated_at', '>=', now()->subDays($days));
                });
        });
    }

    /**
     * Order a listing by the support workflow: open before resolved, brand-new
     * before answered, high priority first, then most recently updated.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeWorkflowOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN status = '".self::STATUS_RESOLVED."' THEN 1 ELSE 0 END asc")
            ->orderByRaw("CASE
                WHEN created_at = updated_at THEN 0
                WHEN priority = 'high' THEN 1
                WHEN priority = 'standard' THEN 2
                ELSE 3 END asc")
            ->orderByRaw("CASE
                WHEN status = '".self::STATUS_WAIT_MODERATOR."' THEN 1
                WHEN status = '".self::STATUS_WAIT_CUSTOMER."' THEN 2
                ELSE 3 END asc")
            ->orderBy('updated_at', 'desc');
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isOpen(): bool
    {
        return ! $this->isResolved();
    }

    /**
     * Whether this is a freshly opened ticket no one has answered yet.
     */
    public function isNew(): bool
    {
        return $this->created_at !== null
            && $this->updated_at !== null
            && $this->created_at->equalTo($this->updated_at);
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
