# Credits (v0.46.0)

An internal currency: the payer holds a balance of whole credits and the host's
features spend it. The core keeps the wallet, holds for work in progress and a
ledger of every movement; the host decides what things cost and when to charge.

The module ships **no route, page, menu item or switch**. It does nothing until
host code calls `CreditService`; its three tables are created by `migrate`
regardless (`larafoundry_credit_wallets`, `larafoundry_credit_holds`,
`larafoundry_credit_transactions`).

## Who pays

The owner of a wallet is the payer, by the same rule as the billing module
(`BillingSubject`): the user in `personal` tenancy, the user's active company in
`teams`. `CreditService::ownerFor($user)` resolves it; every other method takes
the owner model directly, so a host may also give credits to any other saved
model. Owner keys are integers.

## The service

```php
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;

$credits = app(CreditService::class);
$owner = $credits->ownerFor($request->user());

$credits->balance($owner);                                   // int, 0 without a wallet
$credits->credit($owner, 500, 'purchase', $payment, 'Paddle txn_01');
$credits->debit($owner, 3, 'document_unlock', $document);   // throws if not enough
$credits->debitAvailable($owner, 500, 'refund_clawback');   // takes what is there
$credits->adjust($owner, 120, 'manual_fix', 'ticket #7');   // set to a figure

// Work that runs a while: hold → charge as it goes → release the rest.
$hold = $credits->hold($owner, 300, $job, 'reserve');       // throws if not enough
$credits->charge($hold, 120, 'transcribe', meta: ['model_tokens' => 9000]);
$credits->charge($hold, 60, 'translate');
$credits->release($hold, 'refund');                          // 120 back, hold closed

$credits->history($owner, 25);                               // paginator, newest first
```

| Rule | Why |
|---|---|
| Amounts are whole, non-negative credits; **zero writes nothing** | How the host arrives at an amount is its own business. A host that bills small usage (per thousand characters, accumulated across requests) keeps its own accumulator and calls with whatever whole credits it has reached - most calls are zero. |
| `debit()` and `hold()` throw `InsufficientCreditsException`, nothing moved | A refusal to spend is the one outcome a caller must not overlook. The check and the deduction happen under the same wallet lock, so two requests never both spend the same credits. |
| `$allowNegative: true` on `debit()` / `hold()` / `adjust()` | For an operator whose work must never be refused. Nobody else goes below zero; `adjust()` to a negative figure without it throws. |
| One hold per work (`$subject`) | A repeated `hold()` for the same work returns the existing hold and moves nothing - **even a released one**, which then sets nothing aside (check `isClosed()` before re-running finished work). Holding one work through two payers throws. |
| `charge()` takes from the hold first, then the balance, never below zero | The work may cost more than was held. What even the balance cannot cover is kept on the row as `uncovered` (also `CreditCharge::$uncovered` and a log warning), so what was paid is always `cost - uncovered`. |
| `release()` writes its row even when nothing is left | Closing the hold belongs to the work's history. A second release returns `null`. |
| ⚠️ `charge()`, `credit()`, `debit()` are **not idempotent** | A repeated call moves credits again. A step that may run twice (a retried queue job, a re-delivered payment webhook) must guard the repeat itself - e.g. by recording the payment first, under its own unique key. |
| `$reason` is the host's word, 1-64 characters | The core never interprets it; it labels the row and lets reports group by it. |

## The ledger

One row per movement, never edited. `kind` is what the movement did: `hold`,
`charge`, `release`, `credit`, `debit`, `adjust`. Two numbers per row:

- `amount` - how the **balance** moved (signed). **balance == SUM(amount)** for every wallet;
- `cost` - for a `charge`, what the work cost. A charge from a hold has `amount` 0
  (the credits left the balance at `hold`), or minus the shortfall taken from the balance;
- `uncovered` - the part of a charge's `cost` nobody paid (neither hold nor balance had it).

`history()` returns the rows as models, `meta` and `note` included - a host that
shows them to a person picks the fields it means to show.

`balance_after` is the balance right after the row. `subject` is what the row
concerns - a hold copies its work there, so every movement of one job is one
indexed query (`subject_type` + `subject_id`). `meta` is the host's own JSON.

`value_cents` snapshots what the movement was worth (`|amount|`, or `cost` for a
charge, times the credit's value), so a later price change never rewrites past
rows. The value comes from the `CreditUnitValue` contract - by default
`larafoundry.credits.unit_cents` (env `LARAFOUNDRY_CREDITS_UNIT_CENTS`, default 1);
a host whose price is an admin setting binds its own:

```php
$this->app->bind(CreditUnitValue::class, MySettingsCreditValue::class);
```

`php artisan larafoundry:credits-verify` checks that every balance equals its
ledger and every closed hold is empty; it never repairs and exits non-zero on a
mismatch (a balance written around the service).

## The currency's name

The code never spells the word. It lives in `lang/{locale}/credits.php` with
every plural form (`name`, `amount`, `title`), and the core ships `en` and `uk`:

```php
$credits->amount(5);   // "5 credits" / "5 кредитів"
$credits->name(1);     // "credit"
```

A host renames it - or adds a language - with an override file, merged over the
core's (only the keys it sets change):

```php
// lang/vendor/larafoundry/ru/credits.php
return [
    'name' => 'кредит|кредита|кредитов',
    'amount' => ':count кредит|:count кредита|:count кредитов',
    'title' => 'Кредиты',
    'reasons' => [
        'purchase' => 'Покупка',
        'gift' => 'Подарок при регистрации',
    ],
];
```

`reasons.<reason>` is the row label for that reason (`CreditTransaction::label()`);
without it the row is labelled by its kind.

On the Vue side, a counted string is a vue-i18n plural message
(`$t('{n} credit | {n} credits', count)`); since v0.46.0 the core's i18n picks the
right one of three forms for `uk`, `ru` and `be`.

## Personal data

The user's own wallet (balance and movements, `meta` included) is a section of
the data export. On account deletion the rows stay - they are the record of money
that moved, and reports need them - but their free text (`note`, `meta`) is
erased. A company's wallet (`teams`) is the company's record and is left alone.

## Not here (yet)

Buying credits with a one-off payment, promo codes, a welcome gift and screens
are the host's for now; a one-off purchase through the billing module is the
next step.
