<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Console\Commands;

use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Models\MagicLoginToken;
use Illuminate\Console\Command;

/**
 * Delete spent and stale magic-login tokens (phase: magic-link).
 *
 * The mirror of {@see PruneSignInRequestsCommand}: removes anything consumed or
 * past its absolute cap, plus a hard floor on `created_at` so a row can never
 * linger more than a day even if a clock drifted. The core does not own a
 * scheduler, so a host wires `Schedule::command('larafoundry:magic-links:prune')
 * ->hourly()` in its console routes (exactly as it does for QR prune).
 */
class PruneMagicLoginTokensCommand extends Command
{
    protected $signature = 'larafoundry:magic-links:prune';

    protected $description = 'Delete consumed and expired magic-login tokens';

    public function handle(): int
    {
        $deleted = MagicLoginToken::query()
            ->whereNotNull('consumed_at')
            ->orWhere('absolute_expires_at', '<', now())
            ->orWhere('created_at', '<', now()->subDay())
            ->delete();

        $this->info("Pruned {$deleted} magic-login token(s).");

        return self::SUCCESS;
    }
}
