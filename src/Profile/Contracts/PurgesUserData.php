<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Contracts;

use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Erases (or anonymises) one slice of a user's data on account deletion
 * (phase 5.3 — the symmetric mirror of {@see ExportsUserDataProvider}).
 *
 * The same additive-provider idiom as the export seam, turned the other way: the
 * core registers a purger for the identity it owns, and a host/module registers
 * more for the data it owns (orders, tickets…) without the erasure flow knowing
 * every section. The {@see UserDataPurgeRegistry}
 * runs them when the grace period elapses (decision D-5.3-3).
 *
 * A purger decides for itself whether its data is DELETED or ANONYMISED
 * (decision D-5.3-9): personal data the user owns is deleted; legal records that
 * must survive (e.g. invoices) are anonymised and kept. It MUST be idempotent —
 * the daily cron may retry a row whose previous run failed part-way, so running
 * twice must leave the same end state.
 */
interface PurgesUserData
{
    /**
     * Erase or anonymise the data this purger owns for the given user.
     *
     * Must be idempotent: running again on an already-purged user is a no-op.
     */
    public function purgeFor(Authenticatable $user): void;

    /**
     * Unique section key this purger owns (e.g. 'profile', 'tickets'). Two
     * purgers must not share a key.
     */
    public function key(): string;

    /**
     * Ordering across the merged set (lower runs first). The user row is never
     * hard-deleted (it is anonymised), so ordering is not FK-critical — it only
     * makes the sequence predictable.
     */
    public function priority(): int;
}
