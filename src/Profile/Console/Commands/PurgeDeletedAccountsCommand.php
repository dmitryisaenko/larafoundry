<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Console\Commands;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Permanently erase accounts that have sat soft-deleted past the grace period
 * (phase 5.3, decision D-5.3-3).
 *
 * Self-deletion (and the operator delete) only sets `user_deleted_at` — a
 * reversible soft-delete that starts a grace clock. This daily command finds
 * accounts whose grace window (`larafoundry-legal.erasure.grace_days`, default
 * 30) has elapsed and runs the {@see UserDataPurgeRegistry}: the core purger
 * anonymises the identity, each module purger erases its own data. A super-admin
 * can still restore a user during the window (Admin\UserController::undelete),
 * which clears `user_deleted_at` and so removes them from this query.
 *
 * `user_purged_at` makes it idempotent: a purged row is stamped and never
 * reprocessed, and a row whose purge threw (left unstamped) is retried next run
 * (every purger is idempotent). Like the other prune commands, the core does not
 * own a scheduler — a host wires
 * `Schedule::command('larafoundry:purge-deleted-accounts')->daily()`.
 */
class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'larafoundry:purge-deleted-accounts';

    protected $description = 'Permanently erase accounts soft-deleted past the GDPR grace period';

    public function handle(UserDataPurgeRegistry $registry): int
    {
        $graceDays = (int) config('larafoundry-legal.erasure.grace_days', 30);
        $cutoff = now()->subDays($graceDays);

        $model = $this->userModel();
        $purged = 0;

        // chunkById is safe while we mutate the rows: purging stamps
        // user_purged_at (dropping the row from the where), and the id cursor
        // means we never reprocess or skip.
        $model::query()
            ->whereNotNull('user_deleted_at')
            ->whereNull('user_purged_at')
            ->where('user_deleted_at', '<', $cutoff)
            ->chunkById(100, function ($users) use ($registry, &$purged): void {
                foreach ($users as $user) {
                    if ($this->purgeOne($registry, $user)) {
                        $purged++;
                    }
                }
            });

        $this->info("Purged {$purged} deleted account(s).");

        return self::SUCCESS;
    }

    /**
     * Erase one account inside a transaction, stamping it purged. Returns whether
     * the purge succeeded; a failure is reported and skipped (retried next run).
     */
    protected function purgeOne(UserDataPurgeRegistry $registry, Model $user): bool
    {
        try {
            DB::transaction(function () use ($registry, $user): void {
                $registry->purge($user);
                $user->forceFill(['user_purged_at' => now()])->save();
            });
        } catch (Throwable $e) {
            $this->error("Failed to purge account #{$user->getKey()}: {$e->getMessage()}");

            return false;
        }

        Activity::log(
            description: 'account.purged',
            logName: 'default',
            properties: ['user_id' => $user->getKey()],
            subject: $user,
            geoSync: false,
        );

        return true;
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
