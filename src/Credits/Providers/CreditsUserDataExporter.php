<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Providers;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditWallet;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exports the user's own credit wallet and its movements (v0.46.0).
 *
 * Only a wallet the USER owns - in `personal` tenancy, where the user is the
 * payer. A `teams` wallet belongs to the company and is not one member's
 * personal data, so it is left out.
 *
 * `meta` is exported too: it is the host's free payload, and the core cannot
 * tell whether it holds anything personal. Its mirror, {@see CreditsUserDataPurger},
 * erases `note` and `meta` on deletion and keeps the money rows.
 */
class CreditsUserDataExporter implements ExportsUserDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(Authenticatable $user): array
    {
        if (! $user instanceof Model) {
            return [];
        }

        $wallet = CreditWallet::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->first();

        if ($wallet === null) {
            return [];
        }

        return [
            'balance' => $wallet->balance,
            'movements' => $wallet->transactions()
                ->orderBy('id')
                ->get()
                ->map(fn (CreditTransaction $row) => [
                    'kind' => $row->kind,
                    'reason' => $row->reason,
                    'amount' => $row->amount,
                    'cost' => $row->cost,
                    'balance_after' => $row->balance_after,
                    'note' => $row->note,
                    'meta' => $row->meta,
                    'at' => $row->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
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
