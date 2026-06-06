<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Contracts;

use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contributes one section of a user's personal-data export (phase 5.1 seam).
 *
 * The additive-provider idiom used for menus (phase 2.3) and dashboard widgets
 * (phase 3.4), aimed at GDPR data portability: the core registers a provider for
 * the identity it owns, and a host/module registers more for the data it owns
 * (orders, tickets, …) without the export flow knowing every section. The
 * {@see UserDataExportRegistry}
 * collects them.
 *
 * Phase 5.1 ships the seam only — the registry and the core provider are wired,
 * but the download endpoint/UI that streams the collected sections is phase 5.3.
 */
interface ExportsUserDataProvider
{
    /**
     * The exportable data this provider holds for the given user.
     *
     * @return array<string, mixed>
     */
    public function exportFor(Authenticatable $user): array;

    /**
     * Unique section key under which this provider's data is filed in the export
     * (e.g. 'profile', 'tickets'). Two providers must not share a key.
     */
    public function key(): string;

    /**
     * Ordering across the merged set (lower runs first).
     */
    public function priority(): int;
}
