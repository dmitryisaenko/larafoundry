<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Providers;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditWallet;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Erases the free text of the user's own ledger on account deletion (v0.46.0).
 *
 * The rows themselves stay: they are the record of money that moved, and every
 * report built on the ledger needs them (amounts, kinds, reasons, dates). What
 * goes is `note` and `meta` - the host's free text and payload, which may carry a
 * document title or anything else personal the core cannot see into.
 *
 * Only a wallet the user owns (`personal` tenancy); a company's wallet is the
 * company's record, not one member's.
 */
class CreditsUserDataPurger implements PurgesUserData
{
    public function purgeFor(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $wallet = CreditWallet::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->first();

        $wallet?->transactions()->update(['note' => null, 'meta' => null]);

        // Purchases likewise: the money rows stay, the host's data on them goes -
        // also on the ones the user made for a company (`teams`), since that data
        // may be about the person who pressed buy.
        CreditPurchase::query()
            ->where(fn ($query) => $query
                ->where(fn ($owned) => $owned
                    ->where('owner_type', $user->getMorphClass())
                    ->where('owner_id', $user->getKey()))
                ->orWhere('user_id', $user->getKey()))
            ->update(['note' => null, 'meta' => null]);
    }

    public function key(): string
    {
        return 'credits';
    }

    public function priority(): int
    {
        return 60;
    }
}
