<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Jobs;

use Dmitryisaenko\LaraFoundry\Notifications\Events\BroadcastNotificationSent;
use Dmitryisaenko\LaraFoundry\Notifications\Http\Filters\BroadcastRecipientFilter;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Fans a super-admin broadcast out to its filtered audience (phase 4.1).
 *
 * Queued and chunked on purpose (security finding #2): the donor attached every
 * matched user in one synchronous transaction, which a large user base turns
 * into a request-blocking, memory-heavy operation. Here the audience is resolved
 * from the notification's stored `recipient_filters`, walked in id-ordered
 * chunks, and inserted with `insertOrIgnore` so a re-run (or the unique pivot
 * backstop) never double-delivers. Runs on the database queue — no daemon.
 */
class SendBroadcastNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $notificationId,
    ) {}

    public function handle(): void
    {
        $notification = Notification::find($this->notificationId);

        // Only act while the broadcast is mid-send. The controller flips it to
        // 'sending' before dispatch; once this job has finished it is 'sent', so
        // a queue retry returns here rather than re-flipping the status and
        // re-firing the audited BroadcastNotificationSent event. The chunked
        // insertOrIgnore below is idempotent either way — this keeps the audit
        // entry one-shot too.
        if ($notification === null || ! $notification->isAdmin() || $notification->status !== 'sending') {
            return;
        }

        $batchSize = (int) config('larafoundry-notifications.broadcast.batch_size', 1000);

        $this->recipientQuery($notification)
            ->select('id')
            ->chunkById($batchSize, function (Collection $users) use ($notification) {
                $now = now();

                $rows = $users->map(fn (Model $user) => [
                    'notification_id' => $notification->getKey(),
                    'user_id' => $user->getKey(),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('larafoundry_notification_user')->insertOrIgnore($rows);
                }
            });

        $notification->update(['status' => 'sent']);

        event(new BroadcastNotificationSent($notification));
    }

    /**
     * The audience: the configured user model, narrowed by the stored filters,
     * with the platform super-admin excluded so a broadcast never targets the
     * operator account (finding #4 — exclusion is config-driven, not by a
     * hardcoded email comparison).
     *
     * @return Builder<Model>
     */
    protected function recipientQuery(Notification $notification)
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        // Filter values come from JSON; cast scalars to string so the filter
        // methods' string parameters never trip strict_types.
        $filters = array_map(
            fn ($value) => is_scalar($value) ? (string) $value : $value,
            $notification->recipient_filters ?? [],
        );

        $query = (new BroadcastRecipientFilter(new Request($filters)))->apply($model::query());

        $superAdminEmail = config('larafoundry.security.super_admin.email');

        if (is_string($superAdminEmail) && $superAdminEmail !== '') {
            $query->where('email', '!=', $superAdminEmail);
        }

        return $query;
    }
}
