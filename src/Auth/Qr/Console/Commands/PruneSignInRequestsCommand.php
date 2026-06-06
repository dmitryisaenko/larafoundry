<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Qr\Console\Commands;

use Dmitryisaenko\LaraFoundry\Auth\Qr\Models\SignInRequest;
use Illuminate\Console\Command;

/**
 * Delete spent and stale QR sign-in requests (phase 1.4 part 2).
 *
 * The donor never cleaned the table, so expired rows accumulated forever
 * (donor hole #5). Removes anything past its sliding `expires_at`, plus a
 * hard floor on `created_at` so a row can never linger more than a day even if
 * its clock somehow drifted. The core does not own a scheduler, so a host wires
 * `Schedule::command('larafoundry:qr:prune')->hourly()` in its console routes.
 */
class PruneSignInRequestsCommand extends Command
{
    protected $signature = 'larafoundry:qr:prune';

    protected $description = 'Delete expired and stale QR sign-in requests';

    public function handle(): int
    {
        $deleted = SignInRequest::query()
            ->where('expires_at', '<', now())
            ->orWhere('created_at', '<', now()->subDay())
            ->delete();

        $this->info("Pruned {$deleted} QR sign-in request(s).");

        return self::SUCCESS;
    }
}
