<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Console\Commands;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks the two rules the credit ledger lives by (v0.46.0):
 *   - every wallet's balance equals the sum of its ledger amounts;
 *   - every closed hold has nothing left in it.
 *
 * Read-only: it reports, it never repairs. A mismatch means a balance was
 * written outside CreditService, and the fix is a decision (which side is
 * right), not arithmetic. Exits non-zero on any mismatch, so a schedule or a
 * deploy step can alert on it.
 */
class VerifyCreditsCommand extends Command
{
    protected $signature = 'larafoundry:credits-verify';

    protected $description = 'Check that every credit balance equals the sum of its ledger';

    public function handle(): int
    {
        $sums = DB::table('larafoundry_credit_transactions')
            ->select('wallet_id', DB::raw('SUM(amount) as total'))
            ->groupBy('wallet_id');

        $mismatched = DB::table('larafoundry_credit_wallets as w')
            ->leftJoinSub($sums, 's', 's.wallet_id', '=', 'w.id')
            ->whereRaw('w.balance <> COALESCE(s.total, 0)')
            ->orderBy('w.id')
            ->get(['w.id', 'w.owner_type', 'w.owner_id', 'w.balance', DB::raw('COALESCE(s.total, 0) as ledger')]);

        foreach ($mismatched as $row) {
            $this->error("Wallet #{$row->id} ({$row->owner_type} #{$row->owner_id}): balance {$row->balance}, ledger {$row->ledger}.");
        }

        $leaking = CreditHold::query()->whereNotNull('closed_at')->where('remaining', '>', 0)->count();

        if ($leaking > 0) {
            $this->error("{$leaking} closed hold(s) still hold credits.");
        }

        if ($mismatched->isEmpty() && $leaking === 0) {
            $wallets = DB::table('larafoundry_credit_wallets')->count();
            $this->info("All {$wallets} credit wallet(s) match their ledger.");

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
