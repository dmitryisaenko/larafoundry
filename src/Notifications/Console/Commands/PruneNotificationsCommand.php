<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Console\Commands;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes old in-app notifications (phase 4.1, security finding #6 — retention).
 *
 * Deletes read pivot rows older than `read_lifetime_days`, then any SENT
 * notification left with no recipients (so a stale broadcast does not linger
 * with its PII payload). Drafts and in-flight sends are never touched. Like the
 * activity-log clean, the host schedules it — the core does not.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'larafoundry:notifications-prune';

    protected $description = 'Delete old read in-app notifications past the retention window';

    public function handle(): int
    {
        if (! config('larafoundry-notifications.retention.enabled', true)) {
            $this->info('Notification retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $days = (int) config('larafoundry-notifications.retention.read_lifetime_days', 30);
        $cutoff = now()->subDays($days);

        $pivots = DB::table('larafoundry_notification_user')
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->delete();

        // Only SENT rows can be orphaned away — a draft has no recipients yet and
        // must survive until it is sent.
        $orphans = Notification::query()
            ->sent()
            ->whereDoesntHave('users')
            ->delete();

        $this->info("Pruned {$pivots} read recipient row(s) and {$orphans} orphaned notification(s).");

        return self::SUCCESS;
    }
}
