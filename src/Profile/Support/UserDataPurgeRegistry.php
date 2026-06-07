<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Support;

use Dmitryisaenko\LaraFoundry\Profile\Console\Commands\PurgeDeletedAccountsCommand;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Runs every registered {@see PurgesUserData} when an account is erased
 * (phase 5.3 — the symmetric mirror of {@see UserDataExportRegistry}).
 *
 * Registered as a singleton (like the export registry / MenuBuilder) so the
 * core's purger and any host/add-on purgers persist for the request; a host adds
 * its own by resolving this and calling addProvider, exactly as for the export.
 * The {@see PurgeDeletedAccountsCommand}
 * calls {@see purge()} once a deleted account has sat past its grace period.
 */
class UserDataPurgeRegistry
{
    /**
     * @var array<int, PurgesUserData>
     */
    protected array $providers = [];

    /**
     * Register a purger, keeping the set ordered by priority (lower first).
     *
     * Section keys must be unique, mirroring the export registry: a second
     * purger sharing a key is almost certainly a wiring mistake, so we fail loud
     * rather than run an unexpected eraser.
     *
     * @throws InvalidArgumentException when a purger with the same key() exists
     */
    public function addProvider(PurgesUserData $provider): self
    {
        foreach ($this->providers as $existing) {
            if ($existing->key() === $provider->key()) {
                throw new InvalidArgumentException(
                    "A user-data purger for section [{$provider->key()}] is already registered.",
                );
            }
        }

        $this->providers[] = $provider;

        usort(
            $this->providers,
            static fn (PurgesUserData $a, PurgesUserData $b) => $a->priority() <=> $b->priority(),
        );

        return $this;
    }

    /**
     * Run every purger in priority order, erasing/anonymising the user's data.
     *
     * Each purger is idempotent, so the whole run is safe to repeat (the cron
     * retries a row whose previous run failed before it was marked purged).
     */
    public function purge(Authenticatable $user): void
    {
        foreach ($this->providers as $provider) {
            $provider->purgeFor($user);
        }
    }

    /**
     * The registered purgers, in priority order.
     *
     * @return array<int, PurgesUserData>
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
