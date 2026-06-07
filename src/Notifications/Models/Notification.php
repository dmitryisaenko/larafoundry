<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Models;

use Dmitryisaenko\LaraFoundry\Concerns\Filterable;
use Dmitryisaenko\LaraFoundry\Notifications\Concerns\HasNotifications;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * The core's in-app notification (phase 4.1).
 *
 * One row is either an ADMIN broadcast (`*_translations` text, fanned out to a
 * filtered audience through the pivot) or a SYSTEM notification (`*_key`
 * translation keys + `params`, pushed by the host's domain via
 * {@see NotificationService}).
 * Lives in `larafoundry_notifications` to avoid Laravel's reserved
 * `notifications` table; the relation is `appNotifications()` on the user (the
 * {@see HasNotifications}
 * trait) to avoid clashing with `Notifiable::notifications()`.
 *
 * @property int $id
 * @property string $code
 * @property string $notification_type
 * @property string $status
 * @property bool $send_mail
 * @property string|null $title_key
 * @property string|null $body_key
 * @property array<string, mixed>|null $params
 * @property array<string, string>|null $title_translations
 * @property array<string, string>|null $body_translations
 * @property array<string, mixed>|null $recipient_filters
 * @property array<string, mixed>|null $data
 * @property Carbon|null $visible_from
 * @property Carbon|null $visible_until
 * @property Carbon|null $created_at
 */
class Notification extends Model
{
    use Filterable;

    protected $table = 'larafoundry_notifications';

    protected $fillable = [
        'code',
        'notification_type',
        'status',
        'send_mail',
        'title_key',
        'body_key',
        'params',
        'title_translations',
        'body_translations',
        'recipient_filters',
        'data',
        'visible_from',
        'visible_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_mail' => 'boolean',
            'params' => 'array',
            'title_translations' => 'array',
            'body_translations' => 'array',
            'recipient_filters' => 'array',
            'data' => 'array',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }

    /**
     * The recipients, with each one's `read_at` on the pivot.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->userModel(),
            'larafoundry_notification_user',
            'notification_id',
            'user_id',
        )->withPivot('read_at')->withTimestamps();
    }

    /**
     * Localised title for a locale: a system notification resolves its
     * translation key; an admin broadcast reads the operator's per-locale text,
     * falling back to English.
     */
    public function localizedTitle(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($this->notification_type === 'system' && $this->title_key) {
            return (string) __($this->title_key, $this->params ?? [], $locale);
        }

        $translations = $this->title_translations ?? [];

        return (string) ($translations[$locale] ?? $translations['en'] ?? '');
    }

    /**
     * Localised body — same resolution as {@see self::localizedTitle()}.
     */
    public function localizedBody(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($this->notification_type === 'system' && $this->body_key) {
            return (string) __($this->body_key, $this->params ?? [], $locale);
        }

        $translations = $this->body_translations ?? [];

        return (string) ($translations[$locale] ?? $translations['en'] ?? '');
    }

    /**
     * Whether the notification is within its visibility window right now.
     */
    public function isVisible(): bool
    {
        $now = now();

        if ($this->visible_from && $now->lt($this->visible_from)) {
            return false;
        }

        if ($this->visible_until && $now->gt($this->visible_until)) {
            return false;
        }

        return true;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAdmin(): bool
    {
        return $this->notification_type === 'admin';
    }

    public function scopeAdmin(Builder $query): Builder
    {
        return $query->where('notification_type', 'admin');
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('notification_type', 'system');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    /**
     * Only rows currently inside their visibility window (null bounds = open).
     */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', $now));
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
