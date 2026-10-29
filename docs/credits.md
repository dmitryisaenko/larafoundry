# Credits (v0.46.0, purchases v0.47.0)

An internal currency: the payer holds a balance of whole credits and the host's
features spend it. The core keeps the wallet, holds for work in progress and a
ledger of every movement; the host decides what things cost and when to charge.

The module ships **no route, page, menu item or switch**. It does nothing until
host code calls `CreditService`; its tables are created by `migrate`
regardless (`larafoundry_credit_wallets`, `larafoundry_credit_holds`,
`larafoundry_credit_transactions`, and since v0.47.0 `larafoundry_credit_purchases`).

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

## Buying credits (v0.47.0)

A one-off payment for a number of credits. No page, route or switch here either:
the host draws its own buy screen and calls the services. It works with the
billing module switched **off**.

### What is on sale

`CreditPriceList` answers "what do N credits cost". The default reads
`larafoundry.credits.purchases`; a host whose prices are admin settings binds its
own. Nothing is on sale by default.

```php
'purchases' => [
    'currency' => 'USD',
    'bundles' => [['credits' => 5000, 'price_cents' => 969]],
    'custom' => ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2],
    'paddle' => ['product_id' => env('LARAFOUNDRY_CREDITS_PADDLE_PRODUCT_ID')],
],
```

`custom.min` applies to bundles too (a cheap bundle must not slip under a floor
a gateway's fixed fee makes loss-making); `custom.max` 0 turns custom amounts off.
The price is always the server's: the client sends only the number of credits.

### Paddle

```php
use Dmitryisaenko\LaraFoundry\Credits\Gateways\PaddleCreditPurchases;

$paddle = app(PaddleCreditPurchases::class);

$paddle->canPay($user);                       // false in the sandbox for non-testers
$checkout = $paddle->start($user, 5000, ['product' => 'lorevid'], $returnUrl);
// -> transaction_id, client_side_token, price_cents, currency, sandbox, purchase_id
// The page calls Paddle.Checkout.open({ transactionId }) with the token.
```

`start()` creates the Paddle transaction (inline price against ONE catalog
product, `purchases.paddle.product_id`, with the tax inside the price and the
quantity fixed at 1 whatever the account's defaults) and records a **pending**
purchase with the credits and the price it set. The third argument is the host's own data: it
travels in `custom_data` and is kept on the purchase. `$returnUrl` is sent only
when given - Paddle rejects an unapproved domain.

Credits arrive with Paddle's notification, never in the browser:

```php
// The host's own notification address:
$paddle->handleRequest($request);             // verifies Paddle-Signature, then acts
$paddle->handle($verifiedPayload);            // or: already verified by the host
```

With billing on, the Paddle gateway configured and a webhook secret set, the
core's `/paddle/webhook` route feeds it as well (`HandlePaddleCreditNotification`);
without a secret it is not attached - an unsigned request must not move money.

| Notification | What happens |
|---|---|
| `transaction.completed` / `transaction.paid` | the pending purchase is credited once - only if at least its price was paid, in its currency. **The amount comes from the purchase row, never from `custom_data`** (a buyer may be able to edit that). |
| approved full `refund` / `chargeback` adjustment | the credits are taken back, never below zero (`credits_reversed` keeps what was taken) |
| a refund before the confirmation | kept; the confirmation then settles the purchase as refunded and credits nothing |
| partial refund, pending refund | logged, nothing moves |
| `chargeback_reverse` (the dispute went your way) | logged; the credits taken on the chargeback stay taken - re-crediting is a person's call |
| a transaction without `custom_data.kind = larafoundry_credits`, or with no purchase row here | left alone (subscriptions, another installation's payments) |

Two guards against test payments:

- `cashier.sandbox` (env `PADDLE_SANDBOX`) must agree with the API key's prefix
  (`pdl_sdbx_` / `pdl_live_`), or `start()` throws `GatewayNotConfiguredException`
  - a half-switched environment would record real payments as test ones or the
  other way round, silently. Sandbox purchases carry `sandbox = true`.
- In the sandbox only the people `SandboxTesters` lets through may pay
  (`SandboxPaymentNotAllowedException` otherwise). The default lets nobody
  through; a host binds its own. On live keys it is never asked.

Keys: `larafoundry-billing.paddle.api_key` / `client_side_token` /
`webhook.secret`, falling back to Cashier-Paddle's `PADDLE_*` - shared with a
host's own Paddle code reading the same env names.

### Any other gateway

`CreditPurchaseService` is the one place payments turn into credits:

```php
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;

$purchases = app(CreditPurchaseService::class);

// A checkout the server opens itself: open, then confirm on the notification.
$purchases->open($owner, 5000, 969, 'USD', 'mygateway', $paymentId);
$purchases->confirm('mygateway', $paymentId, $paidCents, 'USD');

// A payment the host verified in one step (a mobile store receipt):
$purchases->fulfil($owner, 1000, 199, 'USD', 'play_store', $purchaseToken, refundKey: $orderId);

$purchases->refund('play_store', $orderId, 'voided');
$purchases->isPaid($owner, 'paddle', $transactionId);   // for the page: do not reopen a paid checkout
```

`fulfil()` refuses a payment opened with `open()` - its gateway's confirmation
is what checks the amount paid.

The purchase row and the credit change in **one transaction**, under the
payment's unique `(gateway, reference)`: a repeated confirmation credits nothing,
and a credit that failed rolls the row back too, so the gateway's retry credits
after all. Cashier-Paddle's own transaction table is not used for this - it is
written before anything acts on it, so a failure after it would make every retry
skip.

New rows are inserted first and an existing one is read only after the unique
key refused the insert: a locking read of a missing row takes a gap lock on
MySQL, and two buyers inserting into the same gap would deadlock each other.

`CreditsPurchased` and `CreditPurchaseRefunded` fire once each, after the
outermost transaction commits (a host transaction that rolls back announces
nothing); a failing listener is reported and never undoes the purchase. The
ledger rows carry the reasons `purchase` and `purchase_refund`, with the purchase
as their subject.

Several sites on one gateway account: see `install.name` in
[billing.md](billing.md#one-gateway-account-several-sites).

## Not here (yet)

Promo codes, a welcome gift and screens are the host's for now.
