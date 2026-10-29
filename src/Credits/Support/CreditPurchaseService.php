<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Events\CreditPurchaseRefunded;
use Dmitryisaenko\LaraFoundry\Credits\Events\CreditsPurchased;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Buying credits (v0.47.0): the one place a confirmed payment turns into credits
 * and a refund takes them back - whatever took the money. A gateway checks its
 * own notification (signature, status) and calls in here.
 *
 * Two ways in:
 *   - open() → confirm(): a checkout the server opens itself (Paddle). The row is
 *     written `pending` when the checkout opens, with the credits and the price
 *     the server set; the confirmation credits what THAT row says, and only when
 *     at least its price was paid. Data that travelled with the payment is never
 *     trusted for the amount - a buyer may be able to edit it.
 *   - fulfil(): a payment confirmed in one step by something the host already
 *     verified (a mobile store receipt).
 *
 * Safe to repeat. The purchase row and the credit change in ONE transaction,
 * under the payment's unique `reference`: a re-delivered confirmation finds the
 * row completed and credits nothing, and a credit that failed rolls the row back
 * with it, so the gateway's retry credits after all. (Cashier-Paddle's own
 * transaction record is NOT used for this: it is written before anything acts on
 * it, so a failure after it would make every retry skip.)
 *
 * New rows are INSERTED FIRST and an existing one is read only after the unique
 * key refused the insert. A locking read of a row that is not there would take a
 * gap lock on MySQL (REPEATABLE READ), and two buyers inserting into the same gap
 * would deadlock each other - different payments, not only the same one twice.
 *
 * Refunds:
 *   - a refund takes the purchased credits back, never below zero - some may be
 *     spent already; `credits_reversed` keeps what was actually taken;
 *   - a refund that arrives before the purchase is confirmed (a deferred payment
 *     method) is kept, and the confirmation, when it lands, settles it and
 *     credits nothing - the refund notification will not come again;
 *   - partial refunds are the gateway's call to make (the Paddle one skips them).
 *
 * The events fire after the outermost transaction commits: a host that calls in
 * from its own transaction, which then rolls back, announces nothing.
 */
class CreditPurchaseService
{
    /** The ledger reason of a purchase's credit. */
    public const REASON_PURCHASE = 'purchase';

    /** The ledger reason of a refund taking a purchase's credits back. */
    public const REASON_REFUND = 'purchase_refund';

    public function __construct(private readonly CreditService $credits) {}

    /**
     * Record a checkout the server has just opened: the credits and the price it
     * set, under the gateway's id for the payment. Nothing is credited until
     * confirm(). Opening the same payment again returns its row.
     *
     * @param  array<string, mixed>  $meta  the host's own data for the purchase
     */
    public function open(
        Model $owner,
        int $credits,
        int $priceCents,
        string $currency,
        string $gateway,
        string $reference,
        ?string $refundKey = null,
        ?int $userId = null,
        bool $sandbox = false,
        array $meta = [],
    ): CreditPurchase {
        $this->assertPurchase($owner, $credits, $priceCents, $gateway, $reference);

        return $this->onceMoreOnConflict(fn () => DB::transaction(function () use (
            $owner, $credits, $priceCents, $currency, $gateway, $reference, $refundKey, $userId, $sandbox, $meta,
        ) {
            $created = $this->insert($this->row(
                $owner, $credits, $priceCents, $currency, $gateway, $reference, $refundKey, $userId, $sandbox, $meta,
            ) + ['status' => CreditPurchase::STATUS_PENDING]);

            if ($created !== null) {
                return $created;
            }

            $existing = $this->purchases($gateway)->where('reference', $reference)->lockForUpdate()->first()
                ?? throw new LogicException('Another payment is already recorded under this refund key.');

            return $this->sameOwner($existing, $owner);
        }, 3));
    }

    /**
     * The gateway confirms an opened checkout was paid. Credits the row's credits
     * once and returns the purchase (the same row on a repeat, nothing moving).
     *
     * Null when the payment is not one this application opened (another
     * installation's, a payment for something else), or when it does not match
     * the row - another currency, or less paid than the price set: the row then
     * stays pending and a warning is logged, because money was taken and a person
     * has to look.
     */
    public function confirm(string $gateway, string $reference, int $paidCents, string $currency): ?CreditPurchase
    {
        $this->assertKeys($gateway, $reference);

        // Only ever READS an existing row (no insert follows), so its locking read
        // cannot deadlock against another buyer's insert.
        [$purchase, $credit, $fresh] = DB::transaction(function () use ($gateway, $reference, $paidCents, $currency) {
            $row = $this->purchases($gateway)->where('reference', $reference)->lockForUpdate()->first();

            if ($row === null) {
                return [null, null, false];
            }

            if ($row->isPending() || $row->status === CreditPurchase::STATUS_REFUND_PENDING) {
                if (strtoupper($currency) !== strtoupper((string) $row->currency) || $paidCents < $row->amount_cents) {
                    Log::warning('larafoundry.credits.purchase_amount_mismatch', [
                        'purchase_id' => $row->id,
                        'gateway' => $gateway,
                        'expected_cents' => $row->amount_cents,
                        'expected_currency' => $row->currency,
                        'paid_cents' => $paidCents,
                        'paid_currency' => $currency,
                    ]);

                    return [null, null, false];
                }

                $row->amount_cents = $paidCents;
            }

            return $this->settle($row);
        }, 3);

        if ($fresh) {
            $this->announce(new CreditsPurchased($purchase, $credit));
        }

        return $purchase;
    }

    /**
     * Record a payment already confirmed and verified by the host (a mobile store
     * receipt) and add its credits, in one step. Returns the purchase - the
     * existing one on a repeat (nothing moves then), or a refund that was waiting
     * for it, now settled with nothing credited.
     *
     * Not for a checkout opened with open(): its gateway's confirmation checks
     * what was paid, and this would credit it without that check (LogicException).
     *
     * `$refundKey` is what a refund will name this payment by; by default the
     * reference itself.
     *
     * @param  array<string, mixed>  $meta  the host's own data for the purchase
     */
    public function fulfil(
        Model $owner,
        int $credits,
        int $amountCents,
        string $currency,
        string $gateway,
        string $reference,
        ?string $refundKey = null,
        ?int $userId = null,
        bool $sandbox = false,
        array $meta = [],
    ): CreditPurchase {
        $this->assertPurchase($owner, $credits, $amountCents, $gateway, $reference);

        [$purchase, $credit, $fresh] = $this->onceMoreOnConflict(fn () => DB::transaction(function () use (
            $owner, $credits, $amountCents, $currency, $gateway, $reference, $refundKey, $userId, $sandbox, $meta,
        ) {
            $fields = $this->row($owner, $credits, $amountCents, $currency, $gateway, $reference, $refundKey, $userId, $sandbox, $meta);
            $row = $this->insert($fields + ['status' => CreditPurchase::STATUS_PENDING]);

            if ($row === null) {
                $row = $this->purchases($gateway)->where('reference', $reference)->lockForUpdate()->first();

                if ($row !== null) {
                    $this->sameOwner($row, $owner);

                    if ($row->isPending()) {
                        throw new LogicException('This payment is a checkout opened with open(): its gateway confirms it, not fulfil().');
                    }
                } else {
                    // A refund that came first knew only its own key - not the
                    // payment's reference, not the owner.
                    $row = $this->purchases($gateway)
                        ->where('refund_key', $fields['refund_key'])
                        ->whereNull('reference')
                        ->where('status', CreditPurchase::STATUS_REFUND_PENDING)
                        ->lockForUpdate()
                        ->first()
                        ?? throw new LogicException('Another payment is already recorded under this refund key.');

                    $row->fill($fields);
                }
            }

            return $this->settle($row, $owner);
        }, 3));

        if ($fresh) {
            $this->announce(new CreditsPurchased($purchase, $credit));
        }

        return $purchase;
    }

    /**
     * Take a refunded or charged-back purchase's credits back. Returns the
     * purchase (also when it was refunded before - nothing moves again).
     *
     * A refund for a payment that is not confirmed yet is kept and returns null:
     * the confirmation will settle it with nothing credited. With
     * `$keepUnknown` false a refund for a payment this application never saw is
     * ignored instead (a gateway that opens every checkout itself knows the
     * payment is somebody else's); true keeps it waiting for a purchase that may
     * still arrive.
     */
    public function refund(string $gateway, string $refundKey, string $reason, bool $keepUnknown = true): ?CreditPurchase
    {
        $this->assertKeys($gateway, $refundKey);

        [$purchase, $taken, $fresh] = $this->onceMoreOnConflict(fn () => DB::transaction(function () use ($gateway, $refundKey, $reason, $keepUnknown) {
            // Insert-first, like fulfil(): the waiting row goes in unless the
            // unique key says the payment is already here.
            if ($keepUnknown && $this->insert([
                'gateway' => $gateway,
                'refund_key' => $refundKey,
                'status' => CreditPurchase::STATUS_REFUND_PENDING,
                'note' => $this->appendNote(null, 'refund arrived before the purchase: '.$reason),
            ]) !== null) {
                Log::warning('larafoundry.credits.refund_waiting_for_purchase', [
                    'gateway' => $gateway,
                    'refund_key' => $refundKey,
                    'reason' => $reason,
                ]);

                return [null, null, false];
            }

            $purchase = $this->purchases($gateway)->where('refund_key', $refundKey)->lockForUpdate()->first();

            if ($purchase === null) {
                return [null, null, false];
            }

            if ($purchase->isPending()) {
                $purchase->forceFill([
                    'status' => CreditPurchase::STATUS_REFUND_PENDING,
                    'note' => $this->appendNote($purchase->note, 'refund arrived before the payment was confirmed: '.$reason),
                ])->save();

                Log::warning('larafoundry.credits.refund_waiting_for_purchase', [
                    'purchase_id' => $purchase->id,
                    'gateway' => $gateway,
                    'reason' => $reason,
                ]);

                return [null, null, false];
            }

            if (! $purchase->isCompleted()) {
                return [$purchase->isRefunded() ? $purchase : null, null, false];
            }

            // The owner may be gone (a deleted account): the purchase is still
            // marked refunded, there is just no wallet left to take from.
            $owner = $this->ownerOf($purchase);
            $taken = $owner !== null
                ? $this->credits->debitAvailable($owner, $purchase->credits, self::REASON_REFUND, $purchase, Str::limit($reason, 200))
                : null;

            $purchase->forceFill([
                'status' => CreditPurchase::STATUS_REFUNDED,
                'credits_reversed' => $taken !== null ? -$taken->amount : 0,
                'refunded_at' => now(),
                'note' => $this->appendNote($purchase->note, 'refunded: '.$reason),
            ])->save();

            return [$purchase, $taken, true];
        }, 3));

        if ($fresh) {
            Log::warning('larafoundry.credits.purchase_refunded', [
                'purchase_id' => $purchase->id,
                'gateway' => $gateway,
                'credits' => $purchase->credits,
                'credits_reversed' => $purchase->credits_reversed,
                'reason' => $reason,
            ]);

            $this->announce(new CreditPurchaseRefunded($purchase, $taken, $reason));
        }

        return $purchase;
    }

    /** The purchase a gateway's refund names, if this application has one. */
    public function forRefundKey(string $gateway, string $refundKey): ?CreditPurchase
    {
        return $refundKey === '' ? null : $this->purchases($gateway)->where('refund_key', $refundKey)->first();
    }

    /**
     * Whether this payment is already paid for this owner - for a checkout page
     * that must not reopen a payment form for money already taken (Paddle.js
     * reopens it for a transaction id left in the address). Scoped by owner, so
     * it never confirms somebody else's purchase.
     */
    public function isPaid(Model $owner, string $gateway, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        return $this->purchases($gateway)
            ->where('reference', $reference)
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereIn('status', [CreditPurchase::STATUS_COMPLETED, CreditPurchase::STATUS_REFUNDED])
            ->exists();
    }

    /**
     * Finish a LOCKED (or just inserted) row whose payment is confirmed: credit a
     * pending one, settle one whose refund came first with nothing credited, leave
     * a finished one alone. Inside the caller's transaction.
     *
     * @return array{0: CreditPurchase|null, 1: CreditTransaction|null, 2: bool} the purchase, its credit, whether it is new
     */
    private function settle(CreditPurchase $row, ?Model $owner = null): array
    {
        if ($row->status === CreditPurchase::STATUS_REFUND_PENDING) {
            $row->forceFill([
                'status' => CreditPurchase::STATUS_REFUNDED,
                'paid_at' => now(),
                'note' => $this->appendNote($row->note, 'settled without credit: the refund arrived first'),
            ])->save();

            Log::warning('larafoundry.credits.refund_settled_before_credit', [
                'purchase_id' => $row->id,
                'gateway' => $row->gateway,
                'withheld_credits' => $row->credits,
            ]);

            return [$row, null, false];
        }

        if (! $row->isPending()) {
            return [$row, null, false];
        }

        $owner ??= $this->ownerOf($row);

        if ($owner === null) {
            // Paid for an account that is gone. Nothing to credit; the row stays
            // pending so the money is visible to whoever reconciles it.
            Log::error('larafoundry.credits.purchase_owner_missing', [
                'purchase_id' => $row->id,
                'gateway' => $row->gateway,
                'reference' => $row->reference,
            ]);

            return [null, null, false];
        }

        $row->forceFill(['status' => CreditPurchase::STATUS_COMPLETED, 'paid_at' => now()])->save();

        $credit = $this->credits->credit($owner, $row->credits, self::REASON_PURCHASE, $row, $row->gateway.' '.$row->reference);

        return [$row, $credit, true];
    }

    /**
     * The fields of a purchase row.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function row(
        Model $owner,
        int $credits,
        int $amountCents,
        string $currency,
        string $gateway,
        string $reference,
        ?string $refundKey,
        ?int $userId,
        bool $sandbox,
        array $meta,
    ): array {
        return [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'user_id' => $userId,
            'credits' => $credits,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'gateway' => $gateway,
            'reference' => $reference,
            'refund_key' => $refundKey !== null && $refundKey !== '' ? $refundKey : $reference,
            'sandbox' => $sandbox,
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * Insert a row, or null when a unique key says it is already there. In a
     * savepoint, so the refused insert does not poison the caller's transaction.
     *
     * @param  array<string, mixed>  $fields
     */
    private function insert(array $fields): ?CreditPurchase
    {
        try {
            return DB::transaction(fn () => CreditPurchase::query()->forceCreate($fields));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * A purchase's owner, global scopes aside: a host that soft-deletes a payer
     * still has its wallet, and a refund must take the credits from it.
     */
    private function ownerOf(CreditPurchase $row): ?Model
    {
        if ($row->owner_type === null || $row->owner_id === null) {
            return null;
        }

        $owner = $row->owner()->withoutGlobalScopes()->first();

        return $owner instanceof Model ? $owner : null;
    }

    private function sameOwner(CreditPurchase $row, Model $owner): CreditPurchase
    {
        // A waiting refund has no owner yet; anything else must be the caller's.
        if ($row->owner_type !== null
            && ($row->owner_type !== $owner->getMorphClass() || (int) $row->owner_id !== (int) $owner->getKey())) {
            throw new LogicException('This payment is already recorded for another owner.');
        }

        return $row;
    }

    /**
     * Run once more after a unique-key conflict the insert-first steps did not
     * absorb (two deliveries racing on the second key of a row).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function onceMoreOnConflict(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException) {
            return $operation();
        }
    }

    /**
     * Fire an event once the money is committed - immediately when no transaction
     * is open, at the outermost commit otherwise, never after a rollback.
     *
     * A failing listener must not undo anything: the purchase is committed by
     * then, and an error would only turn the gateway's notification into a
     * pointless retry (which, finding the purchase, fires nothing).
     */
    private function announce(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** @return Builder<CreditPurchase> */
    private function purchases(string $gateway): Builder
    {
        return CreditPurchase::query()->where('gateway', $gateway);
    }

    private function appendNote(?string $note, string $line): string
    {
        return Str::limit(trim(($note !== null && $note !== '' ? $note.' | ' : '').$line), 252);
    }

    private function assertPurchase(Model $owner, int $credits, int $cents, string $gateway, string $reference): void
    {
        if ($credits < 1 || $cents < 0) {
            throw new InvalidArgumentException('A credit purchase needs at least one credit and a non-negative amount.');
        }

        if (! $owner->exists || $owner->getKey() === null) {
            throw new InvalidArgumentException('The credit purchase owner must be a saved model.');
        }

        $this->assertKeys($gateway, $reference);
    }

    private function assertKeys(string $gateway, string $key): void
    {
        if ($gateway === '' || mb_strlen($gateway) > 32 || $key === '' || mb_strlen($key) > 255) {
            throw new InvalidArgumentException('A credit purchase needs a gateway (1-32 characters) and a payment key (1-255).');
        }
    }
}
