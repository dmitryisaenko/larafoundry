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

### Which gateway, by country (v0.58.0)

A buyer pays through the gateway their country is routed to. The country is
where they **connect from**, read from the header the proxy in front of the site
writes (Cloudflare: `CF-IPCountry`):

```php
use Dmitryisaenko\LaraFoundry\Support\RequestCountry;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPurchaseRoutes;

$country = app(RequestCountry::class)->of($request);   // 'UA', or null when unknown
$route = app(CreditPurchaseRoutes::class)->for($country);
// -> gateway 'liqpay' | 'wayforpay' | 'paddle', currency 'UAH' | null (the main one), bonusPercent
```

⚠️ Trust the header only behind that proxy: a site reachable directly takes it
from whoever sends the request. The header name is
`larafoundry.request_country.header`; empty turns it off. `XX` and `T1` (Tor)
are unknown. A VPN shows the VPN's country - fit for choosing what to offer,
not for proving anything.

The default routes come from config; a host with admin-set routes binds its own
`CreditPurchaseRoutes`:

```php
'routes' => [
    'default' => ['gateway' => 'paddle'],             // everyone else, and an unknown country
    'countries' => [
        'UA' => ['gateway' => 'liqpay', 'currency' => 'UAH'],
        'US' => ['gateway' => 'liqpay', 'bonus_percent' => 5],   // dollars, 5% extra credits
    ],
],
```

A bonus is extra credits on top of the pack, 0-100 percent, rounded down; the
price stays the pack's. A gateway refuses a checkout for a country routed
elsewhere - the page is not the only gate.

### Prices in another currency (v0.58.0)

`CreditPriceLists::in($currency)` gives the list for a currency: the bound
`CreditPriceList` for the main one (and for `null`), and for any other
`larafoundry.credits.purchases.currencies.<CODE>` - a list of its own, never the
main one converted:

```php
'currencies' => [
    'UAH' => [
        'bundles' => [['credits' => 5000, 'price_cents' => 39900]],
        'custom' => ['min' => 2500, 'max' => 0],      // custom amounts need their own cents_per_credit
    ],
],
```

Such a list never falls back to the credit's value (`unit_cents` is in the main
currency). A host with admin-set prices binds its own `CreditPriceLists`.

### LiqPay (v0.58.0)

```php
use Dmitryisaenko\LaraFoundry\Credits\Gateways\LiqPayCreditPurchases;

$liqpay = app(LiqPayCreditPurchases::class);

$checkout = $liqpay->start($user, 5000, $country, ['product' => 'lorevid'],
    resultUrl: route('billing'), serverUrl: route('liqpay.notify'),
    language: app()->getLocale(), receiptEmail: $user->email);
// -> url, data, signature, order_id, credits (bonus included), bonus_credits, price_cents, currency, sandbox
// The page posts `data` and `signature` to `url` in a plain form: LiqPay's own page.

// The notification address (no CSRF):
$liqpay->handleRequest($request);              // verifies the signature, then acts
```

`start()` checks the route first (`CreditPurchaseUnavailableException` for a
country routed elsewhere), prices the pack from the route currency's list, and
records a **pending** purchase with the credits (bonus included) and the price
before the buyer leaves. Its `meta` gets a `liqpay` section with the connection
country, the pack and the bonus; the confirmation adds the card's country
(`card_country`, numeric ISO 3166-1 - `804` is Ukraine), bank and way of payment.
The order id is `lf-<installation>-<ulid>`, unique across sites sharing one shop.

| LiqPay status | What happens |
|---|---|
| `success`, `wait_compensation` | the pending purchase is credited once - only if at least its price was paid, in its currency |
| `reversed` | the credits are taken back, never below zero; a partial refund (`refund_amount` below the price) is logged, nothing moves |
| `wait_accept` | money taken, but the shop is not approved by LiqPay yet and the payment may be cancelled: logged, nothing credited. Approve the shop before going live |
| anything else | not a payment: logged |
| an order with no purchase row here, another shop's `public_key` | left alone |

Signing (checked against LiqPay's own worked example): `data` is base64 of the
JSON, `signature` base64 of the raw `sha3-256(private_key . data . private_key)`,
`version` 7. A notification is accepted under sha3-256 or sha1 (the docs' older
code samples) - both need the private key.

Keys: `larafoundry.credits.purchases.liqpay.public_key` / `private_key` (env
`LIQPAY_PUBLIC_KEY` / `LIQPAY_PRIVATE_KEY`). LiqPay's test pair always starts
with `sandbox_`, and that prefix is the sandbox switch - there is no flag to
forget. Two guards, as for Paddle: a public and a private key from different
pairs throw `GatewayNotConfiguredException`; on a test pair only the people
`SandboxTesters` lets through may pay.

Fiscal receipts: with `liqpay.rro_product_id` (env `LIQPAY_RRO_PRODUCT_ID`) - the
product registered in the LiqPay cabinet under RRO → Каса → Товари - the checkout
carries `rro_info` with one line at the purchase's price and the buyer's email,
for LiqPay's own cash register to send the receipt. Unset, nothing is sent.

A notification that never arrived: LiqPay's documentation does not say whether
it retries. `php artisan larafoundry:credits-liqpay-sync` asks LiqPay about the
pending LiqPay purchases opened 10 minutes to 2 days ago (`--minutes`, `--days`)
and credits the paid ones, once like any confirmation. Schedule it in the host.

### WayForPay (v0.59.0)

Another Ukrainian acquirer, the same shape as LiqPay: route a country to
`'gateway' => 'wayforpay'`.

```php
use Dmitryisaenko\LaraFoundry\Credits\Gateways\WayForPayCreditPurchases;

$wayforpay = app(WayForPayCreditPurchases::class);

$checkout = $wayforpay->start($user, 5000, $country, ['product' => 'lorevid'],
    returnUrl: route('billing.return'), serviceUrl: route('wayforpay.notify'),
    language: app()->getLocale(), email: $user->email);
// -> url, fields, order_id, credits (bonus included), bonus_credits, price_cents, currency, sandbox
// The page posts `fields` to `url` in a plain form: WayForPay's own page.
// A list value (productName, productPrice, productCount) goes as `name[]` inputs, in order.

// The notification address (no CSRF): WayForPay waits for a signed answer.
return response()->json($wayforpay->respondTo($request));   // verifies, acts, answers `accept`
```

`start()` checks the route, prices the pack from the route currency's list and
records a **pending** purchase before the buyer leaves, exactly as LiqPay's does;
its `meta` section is `wayforpay`, and the confirmation adds the card's issuing
bank and its country and the way of payment. The checkout asks for `SALE` (money
taken at once, not held). The order reference is `lf-<installation>-<ulid>`.
The return address may be called with POST - keep it out of CSRF protection.

`respondTo()` throws `WebhookVerificationException` for a signature that does
not hold - answer that with an error, never with `accept`. Any other failure
throws too, so WayForPay repeats the notification (it does for four days until
it gets the answer). An order with no purchase row here is answered and left
alone, so WayForPay stops repeating it.

| WayForPay status | What happens |
|---|---|
| `Approved` | the pending purchase is credited once - only if at least its price was paid, in its currency |
| `Refunded`, `Voided` | the credits are taken back, never below zero; a partial refund (`refundAmount` below the price) is logged, nothing moves |
| `WaitingAuthComplete` | money held on the card, not taken - the shop's settings hold payments: logged, nothing credited |
| anything else (`Pending`, `InProcessing`, `Declined`, `Expired`) | not a payment: logged |
| an order with no purchase row here, another shop's `merchantAccount` | left alone |

Statuses are compared without regard to case (the documentation spells
`InProcessing` both ways).

Signing: lowercase hex HMAC-MD5, keyed with the secret key, of the fields joined
with `;` in a fixed order per message - the checkout's names all products, then
all counts, then all prices (not the order the form lists them in). The
documentation's worked example matches neither of its two published results; the
scheme was confirmed against WayForPay's public test shop. A notification's
`amount` is a JSON number, so it is checked as written and with and without two
decimals - every spelling still needs the key.

Keys: `larafoundry.credits.purchases.wayforpay.merchant_account` / `secret_key` /
`domain` (env `WAYFORPAY_MERCHANT_ACCOUNT` / `WAYFORPAY_SECRET_KEY` /
`WAYFORPAY_DOMAIN`; the domain is the one registered with WayForPay, empty = the
host of `app.url`). There is no separate test address: the public test shop
`test_merch_n1` (secret `flk3409refn54t54t*FNJRET`) is always a test one, and
`wayforpay.sandbox` (env `WAYFORPAY_SANDBOX`) marks your own shop switched to
test mode in its cabinet - the gateway cannot see that switch, so keep the flag
in step with it. In the sandbox only the people `SandboxTesters` lets through
may pay.

`php artisan larafoundry:credits-wayforpay-sync` asks WayForPay (`CHECK_STATUS`)
about the pending WayForPay purchases opened 10 minutes to 2 days ago and
credits the paid ones - for a site down longer than WayForPay keeps repeating,
or a notification this side could not verify. WayForPay answers about an order
it never saw with `Declined` and reason 1127; that is "no such order" and leaves
the purchase pending. Schedule it in the host.

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
