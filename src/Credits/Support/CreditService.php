<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditUnitValue;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\InsufficientCreditsException;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditHold;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditWallet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The one door to credits (v0.46.0): every balance change goes through here.
 *
 * Each operation locks the payer's wallet row, checks, moves the balance and
 * writes its ledger row inside one transaction - so two requests can never both
 * spend the same credits, and balance == SUM(ledger amount) holds by
 * construction (`larafoundry:credits-verify` checks it).
 *
 * Amounts are whole credits. HOW a host arrives at an amount (per minute, per
 * thousand characters accumulated across calls, a flat price) is its own
 * business - the core takes the result. A zero amount is a no-op that writes
 * nothing, so a host that accumulates small usage can call on every request.
 *
 * Two ways to spend:
 *   - debit() - at once, for something that cannot fail halfway;
 *   - hold() → charge() … → release() - for work that runs a while: the credits
 *     leave the balance when the work starts, the work is paid from the hold as
 *     it goes, and the remainder comes back at the end (success or failure).
 *
 * `$reason` is the host's word for why a movement happened (a purchase, a gift,
 * a document unlock). The core never interprets it; it labels the row and lets
 * reports group by it.
 */
class CreditService
{
    public function __construct(private readonly CreditUnitValue $unitValue) {}

    /**
     * The payer for a user: the user itself in `personal` tenancy, its active
     * company in `teams` - the same rule the billing module uses. Null for a
     * guest or a teams user with no active company.
     */
    public function ownerFor(?Authenticatable $user): ?Model
    {
        return BillingSubject::for($user);
    }

    /** The owner's balance; 0 when it has no wallet yet. */
    public function balance(Model $owner): int
    {
        return (int) ($this->walletQuery($owner)->value('balance') ?? 0);
    }

    /** The owner's wallet, created on first use. */
    public function wallet(Model $owner): CreditWallet
    {
        $this->assertPersisted($owner, 'owner');

        $existing = $this->walletQuery($owner)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint inside a caller's transaction, so a lost race does not
            // poison it.
            return DB::transaction(fn () => CreditWallet::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Two first uses raced and the other won. Read it with a LOCKING read:
            // inside a caller's REPEATABLE READ transaction (MySQL's default) a plain
            // read sees the caller's old snapshot, where the winner does not exist.
            return $this->walletQuery($owner)->lockForUpdate()->firstOrFail();
        }
    }

    /** The hold of a piece of work, if one was made. */
    public function holdFor(Model $subject): ?CreditHold
    {
        return $this->holdQuery($subject)->first();
    }

    /**
     * Set credits aside for a piece of work: they leave the balance now.
     *
     * One hold per work - a repeated call for the same `$subject` returns the
     * existing hold and moves nothing, EVEN WHEN THAT HOLD IS ALREADY RELEASED:
     * nothing is then set aside, and the work's further charges come from the
     * balance. A caller that re-runs a finished work checks `isClosed()`. A zero
     * amount still creates the hold (the work is then paid from the balance as it
     * is charged) but writes no row, and is never refused, whatever the balance.
     *
     * `$allowNegative` lets the balance go below zero - for an operator whose
     * work must never be refused. Everyone else gets InsufficientCreditsException
     * with nothing moved.
     *
     * @param  array<string, mixed>  $meta
     */
    public function hold(
        Model $owner,
        int $amount,
        Model $subject,
        ?string $reason = null,
        ?string $note = null,
        array $meta = [],
        bool $allowNegative = false,
    ): CreditHold {
        $this->assertAmount($amount);
        $this->assertReason($reason);
        $this->assertPersisted($subject, 'subject');

        $wallet = $this->wallet($owner);

        return DB::transaction(function () use ($wallet, $amount, $subject, $reason, $note, $meta, $allowNegative) {
            $locked = $this->lockWallet($wallet->id);

            // ⚠️⚠️ INSERT FIRST, READ ONLY ON A CONFLICT (v0.47.1). Looking the hold up
            // first with a locking read - as v0.46.0 did - takes a GAP lock on MySQL's
            // REPEATABLE READ when the hold is not there yet, which is every first
            // hold. New work gets ever-higher keys, so every new hold falls into the
            // same last gap: two payers starting work at the same instant both took
            // that gap lock, then both waited on the other's to insert - a deadlock,
            // and one of the two starts failed. (The purchases of v0.47.0 were fixed
            // the same way.) The insert is a savepoint, so a lost race leaves the
            // caller's transaction usable.
            try {
                $hold = DB::transaction(fn () => CreditHold::query()->create([
                    'wallet_id' => $locked->id,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'amount' => $amount,
                    'remaining' => $amount,
                    'spent' => 0,
                ]));
            } catch (UniqueConstraintViolationException) {
                // The work is held already (a repeat, or a race this one lost - also
                // through another wallet). A LOCKING read of a row that exists locks
                // that row alone, and sees it even inside a caller's old snapshot.
                $existing = $this->holdQuery($subject)->lockForUpdate()->first()
                    ?? throw new LogicException('Credit hold vanished after a conflict.');

                return $this->sameWallet($existing, $locked);
            }

            // A zero hold sets nothing aside, so there is nothing to refuse - even to a
            // payer already below zero (an operator's ordinary state).
            if ($amount > 0) {
                // Not enough: the exception rolls the new hold back with everything else.
                if (! $allowNegative && $locked->balance < $amount) {
                    throw new InsufficientCreditsException($locked->balance, $amount);
                }

                $this->record($locked, CreditTransaction::KIND_HOLD, -$amount, 0, $hold, $reason, $note, $meta);
            }

            return $hold;
        });
    }

    /**
     * Charge work from its hold.
     *
     * Taken from the hold first; a shortfall (the work cost more than was held)
     * comes from the balance, but never below zero. What even the balance cannot
     * cover is `uncovered`: kept on the row (so what was really paid is always
     * `cost - uncovered`), returned and logged. May be called several times (one
     * charge per stage); a closed hold has nothing left, so its charges come from
     * the balance.
     *
     * ⚠️ NOT idempotent: a repeated call charges again. A caller whose step may
     * run twice (a retried queue job) guards the repeat itself.
     *
     * @param  array<string, mixed>  $meta
     */
    public function charge(
        CreditHold $hold,
        int $cost,
        ?string $reason = null,
        ?string $note = null,
        array $meta = [],
    ): CreditCharge {
        $this->assertAmount($cost);
        $this->assertReason($reason);

        if ($cost === 0) {
            return CreditCharge::nothing();
        }

        $charge = DB::transaction(function () use ($hold, $cost, $reason, $note, $meta) {
            $wallet = $this->lockWallet($hold->wallet_id);
            $locked = CreditHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            $fromHold = min($cost, $locked->remaining);
            $shortfall = $cost - $fromHold;
            $fromBalance = $shortfall > 0 ? max(0, min($shortfall, $wallet->balance)) : 0;

            $locked->forceFill([
                'remaining' => $locked->remaining - $fromHold,
                'spent' => $locked->spent + $cost,
            ])->save();

            $uncovered = $shortfall - $fromBalance;

            $transaction = $this->record(
                $wallet, CreditTransaction::KIND_CHARGE, -$fromBalance, $cost, $locked, $reason, $note, $meta,
                uncovered: $uncovered,
            );

            return new CreditCharge($transaction, $fromHold, $fromBalance, $uncovered);
        });

        $hold->refresh();

        if ($charge->uncovered > 0) {
            Log::warning('larafoundry.credits.uncovered_charge', [
                'hold_id' => $hold->id,
                'wallet_id' => $hold->wallet_id,
                'cost' => $cost,
                'uncovered' => $charge->uncovered,
            ]);
        }

        return $charge;
    }

    /**
     * Return what is left of a hold to the balance and close it.
     *
     * Writes its row even when nothing is left - closing the hold is part of the
     * work's history. A second call finds the hold closed and returns null.
     *
     * @param  array<string, mixed>  $meta
     */
    public function release(
        CreditHold $hold,
        ?string $reason = null,
        ?string $note = null,
        array $meta = [],
    ): ?CreditTransaction {
        $this->assertReason($reason);

        $transaction = DB::transaction(function () use ($hold, $reason, $note, $meta) {
            $wallet = $this->lockWallet($hold->wallet_id);
            $locked = CreditHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($locked->isClosed()) {
                return null;
            }

            $remaining = $locked->remaining;

            $locked->forceFill(['remaining' => 0, 'closed_at' => now()])->save();

            return $this->record($wallet, CreditTransaction::KIND_RELEASE, $remaining, 0, $locked, $reason, $note, $meta);
        });

        $hold->refresh();

        return $transaction;
    }

    /**
     * Add credits: a purchase, a gift, a grant. Zero writes nothing.
     *
     * ⚠️ NOT idempotent: a re-delivered payment webhook credits again unless the
     * caller has already recorded that payment.
     *
     * @param  array<string, mixed>  $meta
     */
    public function credit(
        Model $owner,
        int $amount,
        string $reason,
        ?Model $subject = null,
        ?string $note = null,
        array $meta = [],
    ): ?CreditTransaction {
        $this->assertAmount($amount);
        $this->assertReason($reason);

        if ($amount === 0) {
            return null;
        }

        $wallet = $this->wallet($owner);

        return DB::transaction(fn () => $this->record(
            $this->lockWallet($wallet->id), CreditTransaction::KIND_CREDIT, $amount, 0, null, $reason, $note, $meta, $subject,
        ));
    }

    /**
     * Spend credits at once, for something that cannot fail halfway. Zero writes
     * nothing; not enough throws InsufficientCreditsException with nothing moved.
     *
     * @param  array<string, mixed>  $meta
     */
    public function debit(
        Model $owner,
        int $amount,
        string $reason,
        ?Model $subject = null,
        ?string $note = null,
        array $meta = [],
        bool $allowNegative = false,
    ): ?CreditTransaction {
        $this->assertAmount($amount);
        $this->assertReason($reason);

        if ($amount === 0) {
            return null;
        }

        $wallet = $this->wallet($owner);

        return DB::transaction(function () use ($wallet, $amount, $reason, $subject, $note, $meta, $allowNegative) {
            $locked = $this->lockWallet($wallet->id);

            if (! $allowNegative && $locked->balance < $amount) {
                throw new InsufficientCreditsException($locked->balance, $amount);
            }

            return $this->record($locked, CreditTransaction::KIND_DEBIT, -$amount, 0, null, $reason, $note, $meta, $subject);
        });
    }

    /**
     * Take up to `$amount`, as much as the balance holds, never below zero - for
     * taking back credits that may already be spent (a refunded purchase, an
     * operator removing a grant). Returns null when nothing could be taken.
     *
     * @param  array<string, mixed>  $meta
     */
    public function debitAvailable(
        Model $owner,
        int $amount,
        string $reason,
        ?Model $subject = null,
        ?string $note = null,
        array $meta = [],
    ): ?CreditTransaction {
        $this->assertAmount($amount);
        $this->assertReason($reason);

        if ($amount === 0) {
            return null;
        }

        $wallet = $this->wallet($owner);

        return DB::transaction(function () use ($wallet, $amount, $reason, $subject, $note, $meta) {
            $locked = $this->lockWallet($wallet->id);
            $taken = min($amount, max(0, $locked->balance));

            if ($taken === 0) {
                return null;
            }

            return $this->record($locked, CreditTransaction::KIND_DEBIT, -$taken, 0, null, $reason, $note, $meta, $subject);
        });
    }

    /**
     * Set the balance to a figure - a correction, recorded as the difference.
     * Returns null when the balance already is that figure. A figure below zero
     * only with `$allowNegative`, like every other way below zero.
     *
     * @param  array<string, mixed>  $meta
     */
    public function adjust(
        Model $owner,
        int $balance,
        string $reason,
        ?string $note = null,
        array $meta = [],
        bool $allowNegative = false,
    ): ?CreditTransaction {
        $this->assertReason($reason);

        if ($balance < 0 && ! $allowNegative) {
            throw new InvalidArgumentException('A credit balance cannot be set below zero without allowNegative.');
        }

        $wallet = $this->wallet($owner);

        return DB::transaction(function () use ($wallet, $balance, $reason, $note, $meta) {
            $locked = $this->lockWallet($wallet->id);
            $delta = $balance - $locked->balance;

            if ($delta === 0) {
                return null;
            }

            return $this->record($locked, CreditTransaction::KIND_ADJUST, $delta, 0, null, $reason, $note, $meta);
        });
    }

    /**
     * The owner's movements, newest first.
     *
     * @return LengthAwarePaginator<int, CreditTransaction>
     */
    public function history(Model $owner, int $perPage = 25): LengthAwarePaginator
    {
        return CreditTransaction::query()
            ->whereHas('wallet', fn (Builder $wallet) => $wallet
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey()))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * The currency's name for a count ("credit" / "credits", with every plural
     * form of the locale), from `larafoundry::credits.name`.
     */
    public function name(int $count = 2, ?string $locale = null): string
    {
        return trans_choice('larafoundry::credits.name', abs($count), [], $locale);
    }

    /** A count with the currency's name: "1 credit", "5 credits", "-3 credits". */
    public function amount(int $count, ?string $locale = null): string
    {
        return trans_choice('larafoundry::credits.amount', abs($count), ['count' => $count], $locale);
    }

    /**
     * Move a LOCKED wallet's balance and write the movement's row. The single
     * place both happen, so neither can happen without the other.
     *
     * @param  array<string, mixed>  $meta
     */
    private function record(
        CreditWallet $wallet,
        string $kind,
        int $amount,
        int $cost,
        ?CreditHold $hold,
        ?string $reason,
        ?string $note,
        array $meta,
        ?Model $subject = null,
        int $uncovered = 0,
    ): CreditTransaction {
        if ($amount !== 0) {
            $wallet->forceFill(['balance' => $wallet->balance + $amount])->save();
        }

        // A hold's movements concern its work; any other movement, what the
        // caller named.
        $subjectType = $hold !== null ? $hold->subject_type : $subject?->getMorphClass();
        $subjectId = $hold !== null ? $hold->subject_id : $subject?->getKey();

        // A charge is worth what the work cost; every other row, what moved.
        $valued = $kind === CreditTransaction::KIND_CHARGE ? $cost : abs($amount);

        return CreditTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'hold_id' => $hold?->id,
            'kind' => $kind,
            'reason' => $reason,
            'amount' => $amount,
            'cost' => $cost,
            'uncovered' => $uncovered,
            'balance_after' => $wallet->balance,
            'value_cents' => round($valued * $this->unitValue->cents(), 4),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'note' => $note !== null ? Str::limit($note, 252) : null,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function lockWallet(int $id): CreditWallet
    {
        return CreditWallet::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** @return Builder<CreditWallet> */
    private function walletQuery(Model $owner): Builder
    {
        return CreditWallet::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    /** @return Builder<CreditHold> */
    private function holdQuery(Model $subject): Builder
    {
        return CreditHold::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    private function sameWallet(CreditHold $hold, CreditWallet $wallet): CreditHold
    {
        if ($hold->wallet_id !== $wallet->id) {
            throw new LogicException('This work is already held by another payer\'s wallet.');
        }

        return $hold;
    }

    private function assertAmount(int $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('A credit amount cannot be negative.');
        }
    }

    private function assertReason(?string $reason): void
    {
        // Characters, not bytes: the column is varchar(64) in characters.
        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 64)) {
            throw new InvalidArgumentException('A credit reason must be 1-64 characters.');
        }
    }

    private function assertPersisted(Model $model, string $role): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException("The credit {$role} must be a saved model.");
        }
    }
}
