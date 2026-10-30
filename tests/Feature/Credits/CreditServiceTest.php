<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditUnitValue;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\InsufficientCreditsException;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditHold;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditWallet;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function crUser(string $email = 'payer@x.test'): User
{
    return User::create([
        'name' => 'Payer',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

/** A saved record standing in for the host's work (a job, an order). */
function crWork(string $email): User
{
    return crUser($email);
}

function credits(): CreditService
{
    return app(CreditService::class);
}

/** The rule the module lives by: the balance is the sum of the ledger. */
function crLedgerSum(User $owner): int
{
    return (int) CreditTransaction::query()
        ->whereHas('wallet', fn ($w) => $w->where('owner_id', $owner->id))
        ->sum('amount');
}

it('reads a zero balance without creating a wallet', function () {
    $user = crUser();

    expect(credits()->balance($user))->toBe(0)
        ->and(CreditWallet::query()->count())->toBe(0);
});

it('creates one wallet per owner, however many times it is asked', function () {
    $user = crUser();

    $first = credits()->wallet($user);
    $second = credits()->wallet($user);

    expect($second->id)->toBe($first->id)
        ->and(CreditWallet::query()->count())->toBe(1);
});

it('adds credits and writes the movement with its reason and the balance after', function () {
    $user = crUser();

    $row = credits()->credit($user, 300, 'purchase', note: 'Paddle txn_1', meta: ['gateway' => 'paddle']);

    expect(credits()->balance($user))->toBe(300)
        ->and($row->kind)->toBe(CreditTransaction::KIND_CREDIT)
        ->and($row->reason)->toBe('purchase')
        ->and($row->amount)->toBe(300)
        ->and($row->balance_after)->toBe(300)
        ->and($row->note)->toBe('Paddle txn_1')
        ->and($row->meta)->toBe(['gateway' => 'paddle']);
});

it('writes nothing for a zero amount', function () {
    $user = crUser();
    credits()->credit($user, 10, 'gift');

    expect(credits()->credit($user, 0, 'gift'))->toBeNull()
        ->and(credits()->debit($user, 0, 'translation'))->toBeNull()
        ->and(credits()->debitAvailable($user, 0, 'clawback'))->toBeNull()
        ->and(CreditTransaction::query()->count())->toBe(1);
});

it('refuses a negative amount', function () {
    credits()->credit(crUser(), -5, 'gift');
})->throws(InvalidArgumentException::class);

it('refuses a reason longer than the column', function () {
    credits()->credit(crUser(), 5, str_repeat('r', 65));
})->throws(InvalidArgumentException::class);

it('refuses an owner that is not saved', function () {
    credits()->credit(new User, 5, 'gift');
})->throws(InvalidArgumentException::class);

it('spends at once and refuses to overspend, moving nothing', function () {
    $user = crUser();
    credits()->credit($user, 50, 'gift');

    credits()->debit($user, 30, 'document_unlock');

    expect(fn () => credits()->debit($user, 21, 'document_unlock'))
        ->toThrow(InsufficientCreditsException::class);

    expect(credits()->balance($user))->toBe(20)
        ->and(CreditTransaction::query()->count())->toBe(2);
});

it('lets an operator spend past zero only when asked to', function () {
    $user = crUser();

    credits()->debit($user, 40, 'curation', allowNegative: true);

    expect(credits()->balance($user))->toBe(-40)
        ->and(crLedgerSum($user))->toBe(-40);
});

it('takes back only what is there, never below zero', function () {
    $user = crUser();
    credits()->credit($user, 70, 'purchase');

    $row = credits()->debitAvailable($user, 100, 'refund_clawback');

    expect($row->amount)->toBe(-70)
        ->and(credits()->balance($user))->toBe(0)
        ->and(credits()->debitAvailable($user, 100, 'refund_clawback'))->toBeNull();
});

it('corrects the balance to a figure as the difference', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');

    $row = credits()->adjust($user, 60, 'manual_fix', 'support ticket 7');

    expect($row->kind)->toBe(CreditTransaction::KIND_ADJUST)
        ->and($row->amount)->toBe(-40)
        ->and(credits()->balance($user))->toBe(60)
        ->and(credits()->adjust($user, 60, 'manual_fix'))->toBeNull();
});

it('holds credits for work: off the balance at once, into the hold', function () {
    $user = crUser();
    $work = crWork('work@x.test');
    credits()->credit($user, 500, 'purchase');

    $hold = credits()->hold($user, 120, $work, 'reserve');

    expect(credits()->balance($user))->toBe(380)
        ->and($hold->amount)->toBe(120)
        ->and($hold->remaining)->toBe(120)
        ->and($hold->isClosed())->toBeFalse();

    $row = $hold->transactions()->sole();
    expect($row->kind)->toBe(CreditTransaction::KIND_HOLD)
        ->and($row->amount)->toBe(-120)
        ->and($row->subject_id)->toBe($work->id)
        ->and($row->subject_type)->toBe($work->getMorphClass());
});

it('makes one hold per work: a repeat moves nothing', function () {
    $user = crUser();
    $work = crWork('work@x.test');
    credits()->credit($user, 500, 'purchase');

    $first = credits()->hold($user, 120, $work);
    $again = credits()->hold($user, 300, $work);

    expect($again->id)->toBe($first->id)
        ->and(credits()->balance($user))->toBe(380)
        ->and(CreditHold::query()->count())->toBe(1);
});

it('refuses a hold the balance cannot cover, moving nothing', function () {
    $user = crUser();
    credits()->credit($user, 50, 'purchase');

    expect(fn () => credits()->hold($user, 51, crWork('work@x.test')))
        ->toThrow(InsufficientCreditsException::class);

    expect(credits()->balance($user))->toBe(50)
        ->and(CreditHold::query()->count())->toBe(0);
});

it('refuses a hold inside the caller\'s transaction without spoiling it', function () {
    $user = crUser();
    credits()->credit($user, 50, 'purchase');

    // The hold is inserted first (v0.47.1); refusing it rolls back to the savepoint only.
    DB::transaction(function () use ($user) {
        expect(fn () => credits()->hold($user, 51, crWork('work@x.test')))
            ->toThrow(InsufficientCreditsException::class);

        credits()->credit($user, 5, 'gift');
    });

    expect(credits()->balance($user))->toBe(55)
        ->and(CreditHold::query()->count())->toBe(0);
});

it('refuses to hold one work through two payers', function () {
    $a = crUser('a@x.test');
    $b = crUser('b@x.test');
    $work = crWork('work@x.test');
    credits()->credit($a, 100, 'purchase');
    credits()->credit($b, 100, 'purchase');

    credits()->hold($a, 10, $work);
    credits()->hold($b, 10, $work);
})->throws(LogicException::class);

it('creates a zero hold without a row; its charges come from the balance', function () {
    $user = crUser();
    credits()->credit($user, 40, 'purchase');

    $hold = credits()->hold($user, 0, crWork('work@x.test'));
    $charge = credits()->charge($hold, 15, 'settle');

    expect($hold->transactions()->count())->toBe(1)
        ->and($charge->fromHold)->toBe(0)
        ->and($charge->fromBalance)->toBe(15)
        ->and(credits()->balance($user))->toBe(25);
});

it('charges from the hold without moving the balance', function () {
    $user = crUser();
    credits()->credit($user, 500, 'purchase');
    $hold = credits()->hold($user, 120, crWork('work@x.test'));

    $first = credits()->charge($hold, 50, 'settle', meta: ['gemini_tokens' => 9000]);
    $second = credits()->charge($hold, 30, 'usage_translate');

    expect(credits()->balance($user))->toBe(380)
        ->and($first->transaction->amount)->toBe(0)
        ->and($first->transaction->cost)->toBe(50)
        ->and($first->transaction->meta)->toBe(['gemini_tokens' => 9000])
        ->and($second->fromHold)->toBe(30)
        ->and($hold->remaining)->toBe(40)
        ->and($hold->spent)->toBe(80);
});

it('takes a shortfall from the balance, never below zero, and reports the rest', function () {
    $user = crUser();
    credits()->credit($user, 130, 'purchase');
    $hold = credits()->hold($user, 100, crWork('work@x.test'));

    // 100 held, 30 left on the balance; the work costs 150.
    $charge = credits()->charge($hold, 150, 'settle');

    expect($charge->fromHold)->toBe(100)
        ->and($charge->fromBalance)->toBe(30)
        ->and($charge->uncovered)->toBe(20)
        ->and($charge->transaction->amount)->toBe(-30)
        ->and($charge->transaction->cost)->toBe(150)
        ->and(credits()->balance($user))->toBe(0)
        ->and($hold->spent)->toBe(150);
});

it('keeps the unpaid part of a charge on the row, so the paid part is always known', function () {
    $user = crUser();
    credits()->credit($user, 130, 'purchase');
    $hold = credits()->hold($user, 100, crWork('work@x.test'));

    $row = credits()->charge($hold, 150, 'settle')->transaction->fresh();

    expect($row->uncovered)->toBe(20)
        ->and($row->cost - $row->uncovered)->toBe(130);
});

it('charges a released hold from the balance', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');
    $hold = credits()->hold($user, 40, crWork('work@x.test'));
    credits()->release($hold);

    $charge = credits()->charge($hold, 25, 'late_stage');

    expect($charge->fromHold)->toBe(0)
        ->and($charge->fromBalance)->toBe(25)
        ->and(credits()->balance($user))->toBe(75);
});

it('returns a released hold for a repeated hold of the same work, setting nothing aside', function () {
    $user = crUser();
    $work = crWork('work@x.test');
    credits()->credit($user, 100, 'purchase');
    $hold = credits()->hold($user, 40, $work);
    credits()->release($hold);

    $again = credits()->hold($user, 60, $work);

    expect($again->id)->toBe($hold->id)
        ->and($again->isClosed())->toBeTrue()
        ->and(credits()->balance($user))->toBe(100);
});

it('creates a zero hold for a payer already below zero', function () {
    $user = crUser();
    credits()->hold($user, 10, crWork('w1@x.test'), allowNegative: true);

    $hold = credits()->hold($user, 0, crWork('w2@x.test'));

    expect($hold->exists)->toBeTrue()
        ->and($hold->remaining)->toBe(0)
        ->and(credits()->balance($user))->toBe(-10);
});

it('holds past zero only when asked to', function () {
    $user = crUser();

    expect(fn () => credits()->hold($user, 10, crWork('w1@x.test')))
        ->toThrow(InsufficientCreditsException::class);

    $hold = credits()->hold($user, 10, crWork('w2@x.test'), allowNegative: true);

    expect($hold->remaining)->toBe(10)
        ->and(credits()->balance($user))->toBe(-10)
        ->and(crLedgerSum($user))->toBe(-10);
});

it('refuses a debit on an empty wallet', function () {
    credits()->debit(crUser(), 1, 'usage_lookup');
})->throws(InsufficientCreditsException::class);

it('corrects below zero only when asked to', function () {
    $user = crUser();

    expect(fn () => credits()->adjust($user, -5, 'manual_fix'))
        ->toThrow(InvalidArgumentException::class);

    credits()->adjust($user, -5, 'manual_fix', allowNegative: true);

    expect(credits()->balance($user))->toBe(-5);
});

it('measures a reason in characters, not bytes', function () {
    $row = credits()->credit(crUser(), 1, str_repeat('ж', 64));

    expect(mb_strlen($row->reason))->toBe(64);
});

it('writes nothing for a zero charge', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');
    $hold = credits()->hold($user, 50, crWork('work@x.test'));

    $charge = credits()->charge($hold, 0, 'settle');

    expect($charge->transaction)->toBeNull()
        ->and($hold->transactions()->count())->toBe(1);
});

it('returns the remainder of a hold and closes it, once', function () {
    $user = crUser();
    credits()->credit($user, 500, 'purchase');
    $hold = credits()->hold($user, 120, crWork('work@x.test'));
    credits()->charge($hold, 50, 'settle');

    $row = credits()->release($hold, 'refund');

    expect($row->kind)->toBe(CreditTransaction::KIND_RELEASE)
        ->and($row->amount)->toBe(70)
        ->and($hold->isClosed())->toBeTrue()
        ->and($hold->remaining)->toBe(0)
        ->and(credits()->balance($user))->toBe(450)
        ->and(credits()->release($hold, 'refund'))->toBeNull()
        ->and(credits()->balance($user))->toBe(450);
});

it('records the closing of a fully spent hold, so the work history is complete', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');
    $hold = credits()->hold($user, 40, crWork('work@x.test'));
    credits()->charge($hold, 40, 'settle');

    $row = credits()->release($hold);

    expect($row)->not->toBeNull()
        ->and($row->amount)->toBe(0);
});

it('keeps the balance equal to the ledger through a whole run, and says so', function () {
    $user = crUser();
    credits()->credit($user, 1000, 'purchase');
    $hold = credits()->hold($user, 300, crWork('work-1@x.test'));
    credits()->charge($hold, 120, 'settle');
    credits()->charge($hold, 90, 'usage_translate');
    credits()->release($hold);
    credits()->debit($user, 7, 'usage_lookup');
    credits()->debitAvailable($user, 5000, 'refund_clawback');
    credits()->credit($user, 25, 'gift');

    expect(credits()->balance($user))->toBe(crLedgerSum($user))
        ->and(credits()->balance($user))->toBe(25);

    $this->artisan('larafoundry:credits-verify')->assertSuccessful();
});

it('reports a balance written around the service', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');

    DB::table('larafoundry_credit_wallets')->update(['balance' => 150]);

    $this->artisan('larafoundry:credits-verify')->assertFailed();
});

it('reports a closed hold that still holds credits', function () {
    $user = crUser();
    credits()->credit($user, 100, 'purchase');
    $hold = credits()->hold($user, 40, crWork('work@x.test'));
    credits()->release($hold);

    $this->artisan('larafoundry:credits-verify')->assertSuccessful();

    DB::table('larafoundry_credit_holds')->update(['remaining' => 5]);

    $this->artisan('larafoundry:credits-verify')->assertFailed();
});

it('snapshots what a movement was worth, so a later price does not rewrite it', function () {
    config()->set('larafoundry.credits.unit_cents', 0.2);
    $user = crUser();

    $bought = credits()->credit($user, 500, 'purchase');

    config()->set('larafoundry.credits.unit_cents', 5);
    $hold = credits()->hold($user, 100, crWork('work@x.test'));
    $charge = credits()->charge($hold, 40, 'settle');

    expect((float) $bought->fresh()->value_cents)->toBe(100.0)
        ->and((float) $charge->transaction->value_cents)->toBe(200.0);
});

it('takes the credit value from a rebound contract', function () {
    app()->bind(CreditUnitValue::class, fn () => new class implements CreditUnitValue
    {
        public function cents(): float
        {
            return 0.5;
        }
    });
    app()->forgetInstance(CreditService::class);

    $row = credits()->credit(crUser(), 10, 'purchase');

    expect((float) $row->value_cents)->toBe(5.0);
});

it('lists only the owner\'s movements, newest first', function () {
    $user = crUser('a@x.test');
    $other = crUser('b@x.test');
    credits()->credit($user, 10, 'gift');
    credits()->credit($user, 20, 'purchase');
    credits()->credit($other, 99, 'purchase');

    $page = credits()->history($user);

    expect($page->total())->toBe(2)
        ->and($page->items()[0]->amount)->toBe(20)
        ->and(credits()->history(crUser('c@x.test'))->total())->toBe(0);
});

it('labels a row by the host\'s reason when it has wording, by the kind otherwise', function () {
    $user = crUser();
    $gift = credits()->credit($user, 10, 'gift');
    $plain = credits()->credit($user, 10, 'unworded');

    // Load the core's group first: addLines on a group not loaded yet would mark it
    // loaded with this one line only (a host's override FILE merges instead).
    __('larafoundry::credits.title');
    app('translator')->addLines(['credits.reasons.gift' => 'Welcome gift'], 'en', 'larafoundry');

    expect($gift->label())->toBe('Welcome gift')
        ->and($plain->label())->toBe('Added');
});

it('names the currency with every plural form of the locale', function () {
    expect(credits()->amount(1))->toBe('1 credit')
        ->and(credits()->amount(5))->toBe('5 credits')
        ->and(credits()->name(1))->toBe('credit');

    app()->setLocale('uk');

    expect(credits()->amount(1))->toBe('1 кредит')
        ->and(credits()->amount(3))->toBe('3 кредити')
        ->and(credits()->amount(5))->toBe('5 кредитів')
        ->and(credits()->amount(11))->toBe('11 кредитів')
        ->and(credits()->amount(21))->toBe('21 кредит')
        ->and(credits()->amount(-2))->toBe('-2 кредити');
});

it('lets a host rename the currency by an override file, keeping the rest', function () {
    $dir = lang_path('vendor/larafoundry/en');
    $file = $dir.'/credits.php';
    @mkdir($dir, 0777, true);
    file_put_contents($file, "<?php return ['amount' => ':count coin|:count coins'];");

    try {
        app('translator')->setLoaded([]);

        expect(credits()->amount(1))->toBe('1 coin')
            ->and(credits()->amount(4))->toBe('4 coins')
            // Merged, not replaced: the core's other lines survive the override.
            ->and(__('larafoundry::credits.kinds.debit'))->toBe('Spent');
    } finally {
        @unlink($file);
        app('translator')->setLoaded([]);
    }
});

it('pays from the user in personal tenancy', function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    $user = crUser();

    expect(credits()->ownerFor($user)?->is($user))->toBeTrue()
        ->and(credits()->ownerFor(null))->toBeNull();
});
