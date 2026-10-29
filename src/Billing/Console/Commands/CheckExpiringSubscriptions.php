<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Console\Commands;

use Dmitryisaenko\LaraFoundry\Billing\Http\Middleware\CheckAccessMiddleware;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\SubscriptionExpiringNotification;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Emails owners whose subscription is about to lapse (sub-phase B7a), ported from the
 * donor's CheckExpiringSubscriptions and decoupled from the host.
 *
 * The host schedules this daily (`->daily()`); the add-on only registers it. For each
 * reminder window (default 10 and 3 days out) it finds the paying subjects whose
 * `subscription_ends_at` falls on that day and mails the owner of record — the founder
 * in `teams` mode, the payer themselves in `personal`. It NEVER touches access columns —
 * it only warns; the access decision stays with {@see CheckAccessMiddleware}.
 *
 * Differences from the donor:
 *  - idempotency is a cache marker keyed by window + company + target date (the donor
 *    used a host Notification row); the add-on owns no notifications table, and keying
 *    on the date lets a renewed subscription that lands on a NEW expiry day warn again.
 *  - the owner is the founder of record (`created_by_id`); a banned owner is skipped
 *    (the cascade already freezes that company — a dunning email would be noise).
 *  - billing-off is a no-op, so a host that schedules it before flipping billing on is safe.
 */
final class CheckExpiringSubscriptions extends Command
{
    protected $signature = 'larafoundry-billing:check-expiring';

    protected $description = 'Notify company owners of subscriptions expiring soon (no access change).';

    public function handle(): int
    {
        if (! config('larafoundry.billing.enabled')) {
            $this->info('Billing is disabled; nothing to check.');

            return self::SUCCESS;
        }

        foreach ($this->reminderWindows() as $days) {
            $this->notifyExpiringIn($days);
        }

        return self::SUCCESS;
    }

    /**
     * The day-marks to warn on, sanitised to positive ints (default 10 and 3).
     *
     * @return list<int>
     */
    private function reminderWindows(): array
    {
        $windows = config('larafoundry-billing.enforcement.expiry_reminders', [10, 3]);

        $clean = [];

        foreach ((array) $windows as $days) {
            if (is_numeric($days) && (int) $days > 0) {
                $clean[(int) $days] = (int) $days; // de-dupe
            }
        }

        return array_values($clean);
    }

    private function notifyExpiringIn(int $days): void
    {
        // App timezone explicitly so the target day matches the calendar day the app
        // considers "today" (the whereDate compares on it). Idempotency below relies on a
        // SHARED cache store (redis/database/file) — each artisan run is a fresh process,
        // so the `array` driver would never dedupe across runs (sub-phase B7b cron-polish).
        $now = Carbon::now(config('app.timezone'));
        $target = $now->copy()->addDays($days)->toDateString();

        $companies = $this->expiringOn($target);

        $sent = 0;

        foreach ($companies as $company) {
            $owner = $this->recipient($company);

            // No owner, or the owner is banned (the whole company is already frozen by the
            // cascade) → a renewal nudge would be noise.
            if (! is_object($owner) || (method_exists($owner, 'isBlocked') && $owner->isBlocked())) {
                continue;
            }

            // Idempotent per window + company + expiry date: a re-run the same day is a
            // no-op, but a renewal landing on a different date warns again.
            $key = "larafoundry-billing:expiring:{$days}:{$company->getKey()}:{$target}";

            if (! Cache::add($key, true, $now->copy()->addDays($days + 1))) {
                continue;
            }

            if (method_exists($owner, 'notify')) {
                $owner->notify(new SubscriptionExpiringNotification(
                    $company->getKey(),
                    (string) ($company->name ?? ''),
                    $days,
                ));
                $sent++;
            }
        }

        $this->info("Subscriptions expiring in {$days} days: notified {$sent}.");
    }

    /**
     * The paying subjects whose subscription ends on the target day.
     *
     * Reads the CONFIGURED billable, not a hardcoded Company: in `personal` mode the
     * payer is a user and `companies` is empty by definition, so querying it would make
     * this cron a permanent "notified 0" — every renewal reminder silently unsent. The
     * founder relation is only eager-loaded where it exists (a user has no `createdBy`).
     *
     * @return Collection<int, Model>
     */
    private function expiringOn(string $target)
    {
        $class = config('larafoundry-billing.billable_model');

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            $class = Company::class;
        }

        $query = $class::query()
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', $target);

        if (! BillingSubject::isPersonal()) {
            $query->with('createdBy');
        }

        return $query->get();
    }

    /**
     * Who receives the reminder: the founder of record in `teams`, the payer themselves
     * in `personal` (there is no owner column to follow — mirrors NotifyOwnersOfPayment).
     */
    private function recipient(Model $subject): ?object
    {
        if (BillingSubject::isPersonal()) {
            return $subject;
        }

        // getAttribute() resolves a relation when the model defines one and returns null
        // when it does not, so this never fatals on a billable without a founder.
        $owner = $subject->getAttribute('createdBy');

        return is_object($owner) ? $owner : null;
    }
}
