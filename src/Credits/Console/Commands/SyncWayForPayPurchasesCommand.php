<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Console\Commands;

use Dmitryisaenko\LaraFoundry\Credits\Gateways\WayForPayCreditPurchases;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Illuminate\Console\Command;

/**
 * Asks WayForPay how the recent pending purchases stand (v0.59.0) and credits the
 * paid ones - for a notification that never arrived or was never accepted.
 * WayForPay repeats an unanswered notification for four days, but a site down
 * that long, or a notification whose signature this side could not confirm,
 * would otherwise leave a payment uncredited.
 *
 * Only purchases older than `--minutes` (the notification normally comes within
 * seconds; this leaves it room) and younger than `--days` (an unpaid checkout is
 * asked about for that long, then left alone). Safe to repeat: a paid purchase
 * is credited once whoever reports it. A host schedules it; the core does not.
 */
class SyncWayForPayPurchasesCommand extends Command
{
    protected $signature = 'larafoundry:credits-wayforpay-sync
        {--minutes=10 : Skip purchases opened less than this many minutes ago}
        {--days=2 : Skip purchases opened more than this many days ago}';

    protected $description = 'Ask WayForPay about pending credit purchases and credit the paid ones';

    public function handle(WayForPayCreditPurchases $wayforpay): int
    {
        if (! $wayforpay->isConfigured()) {
            $this->info('WayForPay is not configured; nothing to do.');

            return self::SUCCESS;
        }

        $minutes = max(0, (int) $this->option('minutes'));
        $days = max(1, (int) $this->option('days'));

        $pending = CreditPurchase::query()
            ->where('gateway', WayForPayCreditPurchases::GATEWAY)
            ->where('status', CreditPurchase::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->get();

        $settled = 0;

        foreach ($pending as $purchase) {
            if ($wayforpay->sync($purchase) !== null) {
                $settled++;
                $this->line("Purchase #{$purchase->id} ({$purchase->reference}) settled.");
            }
        }

        $this->info("Asked about {$pending->count()} pending WayForPay purchase(s), settled {$settled}.");

        return self::SUCCESS;
    }
}
