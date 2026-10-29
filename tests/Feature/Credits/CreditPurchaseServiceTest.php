<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Credits\Events\CreditPurchaseRefunded;
use Dmitryisaenko\LaraFoundry\Credits\Events\CreditsPurchased;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Dmitryisaenko\LaraFoundry\Credits\Providers\CreditsUserDataExporter;
use Dmitryisaenko\LaraFoundry\Credits\Providers\CreditsUserDataPurger;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function cpUser(string $email = 'buyer@x.test'): User
{
    return User::create([
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function cpService(): CreditPurchaseService
{
    return app(CreditPurchaseService::class);
}

function cpBalance(User $owner): int
{
    return app(CreditService::class)->balance($owner);
}

/** A checkout opened for 500 credits at $5.00 under Paddle id `txn_1`. */
function cpOpen(User $owner, string $reference = 'txn_1'): CreditPurchase
{
    return cpService()->open($owner, 500, 500, 'usd', 'paddle', $reference, userId: $owner->id);
}

it('opens a checkout as a pending purchase and credits nothing yet', function () {
    $owner = cpUser();
    $purchase = cpOpen($owner);

    expect($purchase->status)->toBe(CreditPurchase::STATUS_PENDING)
        ->and($purchase->credits)->toBe(500)
        ->and($purchase->amount_cents)->toBe(500)
        ->and($purchase->currency)->toBe('USD')
        ->and($purchase->refund_key)->toBe('txn_1')
        ->and(cpBalance($owner))->toBe(0);
});

it('returns the same row when the same checkout is opened again', function () {
    $owner = cpUser();

    expect(cpOpen($owner)->id)->toBe(cpOpen($owner)->id)
        ->and(CreditPurchase::query()->count())->toBe(1);
});

it('refuses to open one payment for two owners', function () {
    cpOpen(cpUser('a@x.test'));

    cpOpen(cpUser('b@x.test'));
})->throws(LogicException::class);

it('credits a confirmed checkout once, however often the confirmation arrives', function () {
    Event::fake([CreditsPurchased::class]);
    $owner = cpUser();
    cpOpen($owner);

    $first = cpService()->confirm('paddle', 'txn_1', 500, 'USD');
    $again = cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    expect($first?->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and($again?->id)->toBe($first?->id)
        ->and(cpBalance($owner))->toBe(500)
        ->and(CreditTransaction::query()->where('reason', 'purchase')->count())->toBe(1);

    Event::assertDispatchedTimes(CreditsPurchased::class, 1);
});

it('ties the credit row to its purchase', function () {
    $owner = cpUser();
    $purchase = cpOpen($owner);

    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    $row = CreditTransaction::query()->where('reason', 'purchase')->sole();

    expect($row->subject_type)->toBe($purchase->getMorphClass())
        ->and($row->subject_id)->toBe($purchase->id)
        ->and($row->amount)->toBe(500);
});

it('credits nothing when less was paid than the price set, and keeps the purchase pending', function () {
    $owner = cpUser();
    cpOpen($owner);

    expect(cpService()->confirm('paddle', 'txn_1', 499, 'USD'))->toBeNull()
        ->and(cpBalance($owner))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
});

it('credits nothing when paid in another currency', function () {
    $owner = cpUser();
    cpOpen($owner);

    expect(cpService()->confirm('paddle', 'txn_1', 5000, 'EUR'))->toBeNull()
        ->and(cpBalance($owner))->toBe(0);
});

it('keeps what was really paid when it is more than the price', function () {
    $owner = cpUser();
    cpOpen($owner);

    expect(cpService()->confirm('paddle', 'txn_1', 620, 'USD')?->amount_cents)->toBe(620)
        ->and(cpBalance($owner))->toBe(500);
});

it('ignores a confirmation for a payment it never opened', function () {
    expect(cpService()->confirm('paddle', 'txn_unknown', 500, 'USD'))->toBeNull()
        ->and(CreditPurchase::query()->count())->toBe(0);
});

it('keeps payment references apart per gateway', function () {
    $owner = cpUser();
    cpOpen($owner);

    expect(cpService()->confirm('stripe', 'txn_1', 500, 'USD'))->toBeNull()
        ->and(cpBalance($owner))->toBe(0);
});

it('fulfils a payment the host verified itself in one step, once', function () {
    $owner = cpUser();

    $first = cpService()->fulfil($owner, 1000, 199, 'USD', 'play_store', 'GPA.1', userId: $owner->id, meta: ['product' => 'credits_1000']);
    $again = cpService()->fulfil($owner, 1000, 199, 'USD', 'play_store', 'GPA.1', userId: $owner->id);

    expect($again->id)->toBe($first->id)
        ->and($first->status)->toBe(CreditPurchase::STATUS_COMPLETED)
        ->and($first->meta)->toBe(['product' => 'credits_1000'])
        ->and(cpBalance($owner))->toBe(1000);
});

it('rolls the purchase back with a credit that failed, so the retry credits after all', function () {
    $owner = cpUser();
    cpOpen($owner);

    // The ledger write fails once - standing in for any error inside the credit.
    $calls = 0;
    CreditTransaction::creating(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('database hiccup');
        }
    });

    expect(fn () => cpService()->confirm('paddle', 'txn_1', 500, 'USD'))->toThrow(RuntimeException::class)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING)
        ->and(cpBalance($owner))->toBe(0);

    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    expect(cpBalance($owner))->toBe(500)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_COMPLETED);
});

it('does not undo the purchase when a listener fails', function () {
    $owner = cpUser();
    cpOpen($owner);
    Event::listen(CreditsPurchased::class, fn () => throw new RuntimeException('partner bug'));

    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    expect(cpBalance($owner))->toBe(500)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_COMPLETED);
});

it('takes the credits back on a refund, once', function () {
    Event::fake([CreditPurchaseRefunded::class]);
    $owner = cpUser();
    cpOpen($owner);
    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    $refunded = cpService()->refund('paddle', 'txn_1', 'paddle.refund');
    $again = cpService()->refund('paddle', 'txn_1', 'paddle.refund');

    expect($refunded?->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and($refunded?->credits_reversed)->toBe(500)
        ->and($again?->id)->toBe($refunded?->id)
        ->and(cpBalance($owner))->toBe(0);

    Event::assertDispatchedTimes(CreditPurchaseRefunded::class, 1);
});

it('never takes the balance below zero on a refund of spent credits', function () {
    $owner = cpUser();
    cpOpen($owner);
    cpService()->confirm('paddle', 'txn_1', 500, 'USD');
    app(CreditService::class)->debit($owner, 450, 'transcribe');

    $refunded = cpService()->refund('paddle', 'txn_1', 'paddle.chargeback');

    expect($refunded?->credits_reversed)->toBe(50)
        ->and(cpBalance($owner))->toBe(0);
});

it('settles a refund that arrived before the confirmation, crediting nothing', function () {
    Event::fake([CreditsPurchased::class, CreditPurchaseRefunded::class]);
    $owner = cpUser();
    cpOpen($owner);

    expect(cpService()->refund('paddle', 'txn_1', 'paddle.refund'))->toBeNull()
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_REFUND_PENDING);

    $settled = cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    expect($settled?->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and(cpBalance($owner))->toBe(0)
        ->and(CreditTransaction::query()->count())->toBe(0);

    Event::assertNotDispatched(CreditsPurchased::class);
    Event::assertNotDispatched(CreditPurchaseRefunded::class);
});

it('keeps a refund for an unknown payment waiting, and the one-step payment then credits nothing', function () {
    $owner = cpUser();

    expect(cpService()->refund('play_store', 'order-9', 'voided'))->toBeNull();

    $purchase = cpService()->fulfil($owner, 1000, 199, 'USD', 'play_store', 'token-9', refundKey: 'order-9');

    expect($purchase->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and($purchase->owner_id)->toBe($owner->id)
        ->and(CreditPurchase::query()->count())->toBe(1)
        ->and(cpBalance($owner))->toBe(0);
});

it('ignores a refund for an unknown payment when told the payment cannot be ours', function () {
    expect(cpService()->refund('paddle', 'txn_other', 'paddle.refund', keepUnknown: false))->toBeNull()
        ->and(CreditPurchase::query()->count())->toBe(0);
});

it('marks a refund refunded even when the owner is gone', function () {
    $owner = cpUser();
    cpOpen($owner);
    cpService()->confirm('paddle', 'txn_1', 500, 'USD');
    $owner->delete();

    $refunded = cpService()->refund('paddle', 'txn_1', 'paddle.refund');

    expect($refunded?->status)->toBe(CreditPurchase::STATUS_REFUNDED)
        ->and($refunded?->credits_reversed)->toBe(0);
});

it('says a payment is paid only to its own owner', function () {
    $owner = cpUser('a@x.test');
    $stranger = cpUser('b@x.test');
    cpOpen($owner);

    expect(cpService()->isPaid($owner, 'paddle', 'txn_1'))->toBeFalse();

    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    expect(cpService()->isPaid($owner, 'paddle', 'txn_1'))->toBeTrue()
        ->and(cpService()->isPaid($stranger, 'paddle', 'txn_1'))->toBeFalse()
        ->and(cpService()->isPaid($owner, 'paddle', ''))->toBeFalse();
});

it('refuses a purchase of no credits', function () {
    cpService()->open(cpUser(), 0, 500, 'USD', 'paddle', 'txn_1');
})->throws(InvalidArgumentException::class);

it('exports the user\'s purchases and erases their data on deletion', function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    $owner = cpUser();
    cpService()->open($owner, 500, 500, 'USD', 'paddle', 'txn_1', meta: ['product' => 'lorevid']);
    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    $export = app(CreditsUserDataExporter::class)->exportFor($owner);

    expect($export['purchases'][0])->toMatchArray([
        'credits' => 500,
        'amount_cents' => 500,
        'status' => CreditPurchase::STATUS_COMPLETED,
        'meta' => ['product' => 'lorevid'],
    ]);

    app(CreditsUserDataPurger::class)->purgeFor($owner);

    $row = CreditPurchase::query()->sole();

    expect($row->meta)->toBeNull()
        ->and($row->note)->toBeNull()
        ->and($row->amount_cents)->toBe(500);
});

it('refuses to fulfil a checkout opened with open(), whose gateway checks what was paid', function () {
    $owner = cpUser();
    cpOpen($owner);

    expect(fn () => cpService()->fulfil($owner, 500, 1, 'USD', 'paddle', 'txn_1'))->toThrow(LogicException::class)
        ->and(cpBalance($owner))->toBe(0)
        ->and(CreditPurchase::query()->sole()->status)->toBe(CreditPurchase::STATUS_PENDING);
});

it('announces nothing when the host\'s own transaction rolls back', function () {
    Event::fake([CreditsPurchased::class]);
    $owner = cpUser();

    try {
        DB::transaction(function () use ($owner) {
            cpService()->fulfil($owner, 1000, 199, 'USD', 'play_store', 'GPA.2');

            Event::assertNotDispatched(CreditsPurchased::class);

            throw new RuntimeException('host failed after the purchase');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CreditsPurchased::class);

    expect(CreditPurchase::query()->count())->toBe(0)
        ->and(cpBalance($owner))->toBe(0);
});

it('announces once the host\'s own transaction commits', function () {
    Event::fake([CreditsPurchased::class]);
    $owner = cpUser();

    DB::transaction(fn () => cpService()->fulfil($owner, 1000, 199, 'USD', 'play_store', 'GPA.3'));

    Event::assertDispatchedTimes(CreditsPurchased::class, 1);
});

it('takes a refund from an owner the host hides with a global scope', function () {
    $owner = CpScopedOwner::query()->create([
        'name' => 'Buyer',
        'email' => 'scoped@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
    cpService()->open($owner, 500, 500, 'USD', 'paddle', 'txn_1');
    cpService()->confirm('paddle', 'txn_1', 500, 'USD');

    // The host soft-deletes (here: hides) the payer; its wallet is still there.
    $owner->forceFill(['name' => 'hidden'])->save();

    $refunded = cpService()->refund('paddle', 'txn_1', 'paddle.refund');

    expect($refunded?->credits_reversed)->toBe(500)
        ->and(cpBalance($owner))->toBe(0);
});

it('erases the host data of purchases the user made for a company', function () {
    $buyer = cpUser('member@x.test');
    $company = cpUser('company-stand-in@x.test');
    cpService()->fulfil($company, 1000, 199, 'USD', 'play_store', 'GPA.4', userId: $buyer->id, meta: ['buyer_phone' => '+380']);

    app(CreditsUserDataPurger::class)->purgeFor($buyer);

    expect(CreditPurchase::query()->sole()->meta)->toBeNull();
});

/** A payer the host hides with a global scope (like a soft delete). */
class CpScopedOwner extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn ($query) => $query->where('name', '!=', 'hidden'));
    }
}
