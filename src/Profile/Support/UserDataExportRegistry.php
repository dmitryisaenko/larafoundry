<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Support;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Collects every registered {@see ExportsUserDataProvider} into one user-data
 * export (phase 5.1 seam).
 *
 * Registered as a singleton (like MenuBuilder / DashboardBuilder) so the core's
 * provider and any host/add-on providers persist for the request; a host adds
 * its own by resolving this and calling addProvider, exactly as for the menu.
 * Phase 5.3 consumes {@see collect()} from the export-download flow; phase 5.1
 * only wires the registry so that flow has providers to read.
 */
class UserDataExportRegistry
{
    /**
     * @var array<int, ExportsUserDataProvider>
     */
    protected array $providers = [];

    /**
     * Register a provider, keeping the set ordered by priority (lower first).
     *
     * Section keys must be unique: providers are filed under their key, so a
     * second provider sharing a key would silently overwrite the first's section.
     * A host extending the export adds its own sections through here, so we fail
     * loud on a collision rather than dropping data.
     *
     * @throws InvalidArgumentException when a provider with the same key() exists
     */
    public function addProvider(ExportsUserDataProvider $provider): self
    {
        foreach ($this->providers as $existing) {
            if ($existing->key() === $provider->key()) {
                throw new InvalidArgumentException(
                    "A user-data export provider for section [{$provider->key()}] is already registered.",
                );
            }
        }

        $this->providers[] = $provider;

        usort(
            $this->providers,
            static fn (ExportsUserDataProvider $a, ExportsUserDataProvider $b) => $a->priority() <=> $b->priority(),
        );

        return $this;
    }

    /**
     * Build the export: each provider's data filed under its section key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collect(Authenticatable $user): array
    {
        $export = [];
        foreach ($this->providers as $provider) {
            $export[$provider->key()] = $provider->exportFor($user);
        }

        return $export;
    }

    /**
     * The registered providers, in priority order.
     *
     * @return array<int, ExportsUserDataProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
