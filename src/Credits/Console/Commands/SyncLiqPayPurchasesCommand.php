<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Console\Commands;

use Dmitryisaenko\LaraFoundry\Credits\Gateways\LiqPayCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Illuminate\Console\Command;

/**
 * Asks LiqPay how the recent pending purchases stand (v0.58.0) and credits the
 * paid ones - for a notification that never arrived. LiqPay's documentation does
 * not say whether it retries a notification the site failed to take, so a
 * payment made while the site was down would otherwise stay uncredited.
 *
 * Only purchases older than `--minutes` (the notification normally comes within
 * seconds; this leaves it room) and younger than `--days` (an unpaid checkout is
 * asked about for that long, then left alone). Safe to repeat: a paid purchase
 * is credited once whoever reports it. A host schedules it; the core does not.
 */
class SyncLiqPayPurchasesCommand extends Command
{
    protected $signature = 'larafoundry:credits-liqpay-sync
        {--minutes=10 : Skip purchases opened less than this many minutes ago}
        {--days=2 : Skip purchases opened more than this many days ago}';

    protected $description = 'Ask LiqPay about pending credit purchases and credit the paid ones';

    public function handle(LiqPayCreditPurchases $liqpay): int
    {
        if (! $liqpay->isConfigured()) {
            $this->info('LiqPay is not configured; nothing to do.');

            return self::SUCCESS;
        }

        $minutes = max(0, (int) $this->option('minutes'));
        $days = max(1, (int) $this->option('days'));

        $pending = CreditPurchase::query()
            ->where('gateway', LiqPayCreditPurchases::GATEWAY)
            ->where('status', CreditPurchase::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->get();

        $settled = 0;

        foreach ($pending as $purchase) {
            if ($liqpay->sync($purchase) !== null) {
                $settled++;
                $this->line("Purchase #{$purchase->id} ({$purchase->reference}) settled.");
            }
        }

        $this->info("Asked about {$pending->count()} pending LiqPay purchase(s), settled {$settled}.");

        return self::SUCCESS;
    }
}
