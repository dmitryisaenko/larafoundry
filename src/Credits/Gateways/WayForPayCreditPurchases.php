<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Gateways;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\SandboxPaymentNotAllowedException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentOrigin;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceLists;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPurchaseRoutes;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\CreditPurchaseUnavailableException;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buying credits through WayForPay (v0.59.0), a Ukrainian acquirer: the buyer is
 * sent to WayForPay's own payment page by a form the server signs, and WayForPay
 * tells the server the outcome at `serviceUrl`.
 *
 * The same shape as {@see LiqPayCreditPurchases}: only buyers whose country
 * {@see CreditPurchaseRoutes} sends to this gateway may open a checkout (checked
 * here, not only on the page); the price comes from the route currency's list
 * ({@see CreditPriceLists}), the route's bonus is added, and the pending purchase
 * is written before the buyer leaves - the confirmation credits what the ROW
 * says, never what came with the payment.
 *
 * Protocol (wiki.wayforpay.com, checked 2026-11-19): every signature is the
 * lowercase hex HMAC-MD5, keyed with the shop's secret key, of the listed fields
 * joined with `;`. The order of the fields is fixed per message:
 *   - checkout: merchantAccount, merchantDomainName, orderReference, orderDate,
 *     amount, currency, every productName, every productCount, every productPrice;
 *   - notification and status answer: merchantAccount, orderReference, amount,
 *     currency, authCode, cardPan, transactionStatus, reasonCode;
 *   - our answer to a notification: orderReference, status, time;
 *   - status request: merchantAccount, orderReference.
 * The documentation's own worked example matches neither of its two published
 * results; the scheme was confirmed against WayForPay's public test shop
 * instead (a right key gets "Order Not Found", a wrong one "Invalid signature",
 * and the answer's signature recomputes exactly).
 *
 * A notification is a JSON body and must be answered with a signed `accept`
 * ({@see acceptance()}), or WayForPay repeats it for four days. Its `amount` is
 * a JSON number, so how WayForPay wrote it into the signature (`399` or
 * `399.00`) is not certain: each way of writing the same amount is tried, and
 * every one of them still needs the secret key.
 *
 * Statuses: `Approved` credits; `Refunded` and `Voided` take the credits back (a
 * partial refund is logged and left to a person); `WaitingAuthComplete` (money
 * held, not taken - the checkout asks for `SALE`, so this means the shop's
 * settings differ) credits nothing and is logged; anything else is not a payment.
 * WayForPay answers a status request about an order it never saw with
 * `Declined` and reason 1127 - that is "no such order", not a refusal.
 *
 * Test payments: WayForPay has no separate test address. Its public test shop
 * (`test_merch_n1`) is always a test one, and `wayforpay.sandbox` marks a shop
 * switched to test mode in its cabinet - the gateway cannot see that switch, so
 * the flag must follow it. In the sandbox only the people {@see SandboxTesters}
 * lets through may pay.
 *
 * WayForPay is told nothing about which installation a payment belongs to: every
 * checkout names its own `serviceUrl`, and the order reference carries the
 * installation's name so the orders of several sites on one shop never collide.
 */
final class WayForPayCreditPurchases
{
    /** The gateway name purchases are recorded under. */
    public const GATEWAY = 'wayforpay';

    public const CHECKOUT_URL = 'https://secure.wayforpay.com/pay';

    public const API_URL = 'https://api.wayforpay.com/api';

    /** WayForPay's public test shop (wiki "Test details"). */
    public const TEST_ACCOUNT = 'test_merch_n1';

    /** The reason code of a status answer about an order WayForPay never saw. */
    public const ORDER_NOT_FOUND = 1127;

    /** Statuses (lowercased) that mean the money is the shop's. */
    private const PAID = ['approved'];

    /** Statuses (lowercased) that mean the money went back to the buyer. */
    private const RETURNED = ['refunded', 'voided'];

    /** Our language codes and the ones WayForPay's payment page speaks. */
    private const LANGUAGES = [
        'uk' => 'UA', 'en' => 'EN', 'ru' => 'RU', 'de' => 'DE', 'it' => 'IT', 'ro' => 'RO',
        'es' => 'ES', 'pl' => 'PL', 'sk' => 'SK', 'fr' => 'FR', 'lv' => 'LV', 'cs' => 'CS',
    ];

    public function __construct(
        private readonly CreditPurchaseService $purchases,
        private readonly CreditService $credits,
        private readonly CreditPriceLists $prices,
        private readonly CreditPurchaseRoutes $routes,
        private readonly SandboxTesters $testers,
    ) {}

    /** Whether a checkout can be opened at all: the shop's account and secret key. */
    public function isConfigured(): bool
    {
        return $this->account() !== null && $this->secretKey() !== null;
    }

    /** A test shop: WayForPay's public one, or a shop flagged as in test mode. */
    public function isSandbox(): bool
    {
        return $this->account() === self::TEST_ACCOUNT
            || filter_var(config('larafoundry.credits.purchases.wayforpay.sandbox'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether this person may pay right now - everyone in a live shop, only the
     * testers in a test one.
     */
    public function canPay(?Authenticatable $user): bool
    {
        return ! $this->isSandbox() || $this->testers->allows($user);
    }

    /**
     * Open a checkout for a pack of `$credits` for a buyer connecting from
     * `$country`, and record it as a pending purchase. The page posts `fields` to
     * `url` in a plain form (WayForPay's own page; a list value is sent as
     * `name[]` inputs, in order); the credits arrive with WayForPay's
     * notification at `$serviceUrl`, not here.
     *
     * The buyer gets the pack plus the route's bonus (`credits` in the result,
     * `bonus_credits` of them extra) for the pack's price in the route's currency.
     *
     * `$customData` is the host's own data for the purchase, kept on the row; the
     * gateway adds its own under `wayforpay`. `$email` prefills the payment page.
     *
     * @param  array<string, mixed>  $customData
     * @return array{url: string, fields: array<string, string|list<string>>, order_id: string, purchase_id: int, credits: int, bonus_credits: int, price_cents: int, currency: string, sandbox: bool}
     *
     * @throws CreditPurchaseUnavailableException
     * @throws SandboxPaymentNotAllowedException
     * @throws GatewayNotConfiguredException
     */
    public function start(
        Authenticatable $user,
        int $credits,
        ?string $country,
        array $customData = [],
        ?string $returnUrl = null,
        ?string $serviceUrl = null,
        ?string $language = null,
        ?string $email = null,
    ): array {
        $route = $this->routes->for($country);

        if ($route->gateway !== self::GATEWAY) {
            throw CreditPurchaseUnavailableException::notForCountry(self::GATEWAY, $country);
        }

        if (! $this->canPay($user)) {
            throw new SandboxPaymentNotAllowedException('WayForPay is in test mode and this account may not test payments.');
        }

        $prices = $this->prices->in($route->currency);
        $price = $prices?->priceCents($credits);

        if ($prices === null || $price === null || $price < 1) {
            throw CreditPurchaseUnavailableException::notOnSale($credits);
        }

        $owner = $this->credits->ownerFor($user) ?? throw CreditPurchaseUnavailableException::noPayer();

        if (! $this->isConfigured()) {
            throw new GatewayNotConfiguredException('WayForPay is not configured for credit purchases: larafoundry.credits.purchases.wayforpay.merchant_account and secret_key are required.');
        }

        $domain = $this->domain() ?? throw new GatewayNotConfiguredException('WayForPay needs the shop\'s domain: set larafoundry.credits.purchases.wayforpay.domain or app.url.');

        $currency = $prices->currency();
        $total = $route->creditsFor($credits);
        $orderId = $this->newOrderId();
        $amount = $this->major($price);
        $product = $this->credits->amount($total);

        $fields = [
            'merchantAccount' => (string) $this->account(),
            'merchantDomainName' => $domain,
            'merchantTransactionSecureType' => 'AUTO',
            'merchantTransactionType' => 'SALE',
            'merchantAuthType' => 'SimpleSignature',
            'orderReference' => $orderId,
            'orderDate' => (string) now()->getTimestamp(),
            'amount' => $amount,
            'currency' => $currency,
            'productName' => [$product],
            'productPrice' => [$amount],
            'productCount' => ['1'],
        ];

        $fields['merchantSignature'] = $this->sign([
            $fields['merchantAccount'], $domain, $orderId, $fields['orderDate'], $amount, $currency,
            ...$fields['productName'], ...$fields['productCount'], ...$fields['productPrice'],
        ]);

        $fields += array_filter([
            'language' => $this->language($language),
            'returnUrl' => $returnUrl,
            'serviceUrl' => $serviceUrl,
            'clientEmail' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
        ], fn ($value) => $value !== null && $value !== '');

        $purchase = $this->purchases->open(
            $owner, $total, $price, $currency, self::GATEWAY, $orderId,
            userId: (int) $user->getAuthIdentifier(),
            sandbox: $this->isSandbox(),
            meta: array_merge($customData, [self::GATEWAY => array_filter([
                'country' => $country,
                'pack' => $credits,
                'bonus_percent' => $route->bonusPercent ?: null,
            ], fn ($value) => $value !== null)]),
        );

        return [
            'url' => self::CHECKOUT_URL,
            'fields' => $fields,
            'order_id' => $orderId,
            'purchase_id' => $purchase->id,
            'credits' => $total,
            'bonus_credits' => $total - $credits,
            'price_cents' => $price,
            'currency' => $currency,
            'sandbox' => $this->isSandbox(),
        ];
    }

    /**
     * Verify a notification, act on it and return the signed answer WayForPay
     * waits for - send it back as JSON. Throws, and must not be answered with an
     * `accept`, when the signature does not hold; a failure while acting throws
     * too, so WayForPay repeats the notification later.
     *
     * @return array{orderReference: string, status: string, time: int, signature: string}
     *
     * @throws WebhookVerificationException
     */
    public function respondTo(Request $request): array
    {
        $payment = $this->verifiedPayment($request);

        $this->handle($payment);

        return $this->acceptance((string) ($payment['orderReference'] ?? ''));
    }

    /**
     * Verify a notification's signature and act on it, without building the
     * answer ({@see respondTo()} does both).
     *
     * @throws WebhookVerificationException
     */
    public function handleRequest(Request $request): ?CreditPurchase
    {
        return $this->handle($this->verifiedPayment($request));
    }

    /**
     * The answer to a notification: WayForPay stops repeating it once it gets
     * this, signed, for the same order.
     *
     * @return array{orderReference: string, status: string, time: int, signature: string}
     */
    public function acceptance(string $orderReference): array
    {
        $time = now()->getTimestamp();

        return [
            'orderReference' => $orderReference,
            'status' => 'accept',
            'time' => $time,
            'signature' => $this->sign([$orderReference, 'accept', (string) $time]),
        ];
    }

    /**
     * Act on a VERIFIED payment report - a notification, or the answer to a
     * status request ({@see sync()}). Returns the purchase it settled or took
     * back, null when nothing moved.
     *
     * @param  array<string, mixed>  $payment
     */
    public function handle(array $payment): ?CreditPurchase
    {
        $orderId = (string) ($payment['orderReference'] ?? '');

        if ($orderId === '') {
            return null;
        }

        $account = (string) ($payment['merchantAccount'] ?? '');

        if ($account !== '' && $account !== $this->account()) {
            // Another shop's payment, or the shop switched since the checkout opened.
            Log::warning('larafoundry.credits.wayforpay.foreign_account', ['order_id' => $orderId]);

            return null;
        }

        $purchase = $this->purchases->forRefundKey(self::GATEWAY, $orderId);

        if ($purchase === null) {
            Log::warning('larafoundry.credits.wayforpay.unknown_order', [
                'order_id' => $orderId,
                'status' => $payment['transactionStatus'] ?? null,
            ]);

            return null;
        }

        $status = strtolower((string) ($payment['transactionStatus'] ?? ''));

        if (in_array($status, self::PAID, true)) {
            return $this->purchases->confirm(
                self::GATEWAY,
                $orderId,
                $this->cents($payment['amount'] ?? null),
                (string) ($payment['currency'] ?? ''),
                [self::GATEWAY => $this->evidence($payment)],
            );
        }

        if (in_array($status, self::RETURNED, true)) {
            return $this->refund($purchase, $payment, $status);
        }

        $context = [
            'purchase_id' => $purchase->id,
            'order_id' => $orderId,
            'status' => $payment['transactionStatus'] ?? null,
            'reason_code' => $payment['reasonCode'] ?? null,
        ];

        if ($status === 'waitingauthcomplete') {
            // Money held on the card, not taken: the checkout asks for SALE, so the
            // shop's settings hold payments. Nothing is credited until it is taken.
            Log::warning('larafoundry.credits.wayforpay.held', $context);
        } else {
            Log::info('larafoundry.credits.wayforpay.not_paid', $context);
        }

        return null;
    }

    /**
     * Ask WayForPay how a pending purchase stands and act on the answer - for a
     * notification that never arrived or was never accepted. Null when nothing
     * moved, WayForPay could not be asked, or it knows no such order (a checkout
     * the buyer never paid).
     */
    public function sync(CreditPurchase $purchase): ?CreditPurchase
    {
        if ($purchase->gateway !== self::GATEWAY || ! $purchase->isPending() || ! $this->isConfigured()) {
            return null;
        }

        $reference = (string) $purchase->reference;
        $account = (string) $this->account();

        try {
            $response = Http::asJson()->timeout(20)->post(self::API_URL, [
                'transactionType' => 'CHECK_STATUS',
                'merchantAccount' => $account,
                'orderReference' => $reference,
                'merchantSignature' => $this->sign([$account, $reference]),
                'apiVersion' => 1,
            ]);
        } catch (Throwable $e) {
            Log::warning('larafoundry.credits.wayforpay.status_failed', ['order_id' => $reference, 'error' => $e->getMessage()]);

            return null;
        }

        $answer = $response->successful() ? $response->json() : null;

        if (! is_array($answer) || ! isset($answer['transactionStatus'])) {
            return null;
        }

        if ((int) ($answer['reasonCode'] ?? 0) === self::ORDER_NOT_FOUND) {
            // "Declined" here means WayForPay never saw the order, not a refusal.
            return null;
        }

        // The answer comes straight from WayForPay over TLS, to a request we
        // signed; it names the order we asked about.
        return $this->handle(['orderReference' => $reference] + $answer);
    }

    /**
     * The signature WayForPay expects over `$values`, in the order given: the
     * lowercase hex HMAC-MD5 of them joined with `;`, keyed with the secret key.
     *
     * @param  list<string>  $values
     */
    public function sign(array $values): string
    {
        return hash_hmac('md5', implode(';', $values), (string) $this->secretKey());
    }

    /**
     * Whether `$payment` (a notification or a status answer) carries WayForPay's
     * signature. Its amount is tried as written and with and without two
     * decimals - the same amount each time, and every try needs the key.
     *
     * @param  array<string, mixed>  $payment
     */
    public function verify(array $payment): bool
    {
        $signature = $payment['merchantSignature'] ?? null;

        if ($this->secretKey() === null || ! is_string($signature) || $signature === '') {
            return false;
        }

        $field = fn (string $name): string => is_scalar($payment[$name] ?? null) ? (string) $payment[$name] : '';

        foreach ($this->amountSpellings($payment['amount'] ?? null) as $amount) {
            $expected = $this->sign([
                $field('merchantAccount'), $field('orderReference'), $amount, $field('currency'),
                $field('authCode'), $field('cardPan'), $field('transactionStatus'), $field('reasonCode'),
            ]);

            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The notification's payment, once its signature holds. WayForPay posts JSON;
     * a form-encoded body is read as well.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException
     */
    private function verifiedPayment(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        $payment = is_array($decoded) ? $decoded : $request->all();

        if ($payment === [] || ! $this->verify($payment)) {
            throw new WebhookVerificationException('WayForPay notification signature mismatch.');
        }

        return $payment;
    }

    /**
     * The ways the same amount may have been written into a signature: as it
     * arrived, with two decimals, and with no trailing zeros.
     *
     * @return list<string>
     */
    private function amountSpellings(mixed $amount): array
    {
        if (! is_scalar($amount) || (string) $amount === '') {
            return [''];
        }

        $spellings = [(string) $amount];

        if (is_numeric($amount)) {
            $fixed = number_format((float) $amount, 2, '.', '');
            $spellings[] = $fixed;
            $spellings[] = rtrim(rtrim($fixed, '0'), '.');
        }

        return array_values(array_unique($spellings));
    }

    /**
     * Take back a returned purchase. A partial refund does not map onto taking
     * credits back and is logged for a person instead.
     *
     * @param  array<string, mixed>  $payment
     */
    private function refund(CreditPurchase $purchase, array $payment, string $status): ?CreditPurchase
    {
        $refunded = $payment['refundAmount'] ?? null;

        if (is_numeric($refunded)) {
            $cents = $this->cents($refunded);

            if ($cents > 0 && $cents < $purchase->amount_cents) {
                Log::warning('larafoundry.credits.wayforpay.partial_refund_skipped', [
                    'purchase_id' => $purchase->id,
                    'refunded_cents' => $cents,
                    'amount_cents' => $purchase->amount_cents,
                ]);

                return null;
            }
        }

        return $this->purchases->refund(self::GATEWAY, (string) $purchase->refund_key, 'wayforpay.'.$status, keepUnknown: false);
    }

    /**
     * What the payment report says about where the money came from - the card's
     * issuing bank and its country beside the connection country recorded at the
     * checkout: the two pieces of evidence of where the buyer is.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, string>
     */
    private function evidence(array $payment): array
    {
        $evidence = [];

        foreach (['card_country' => 'issuerBankCountry', 'card_bank' => 'issuerBankName', 'paytype' => 'paymentSystem'] as $key => $field) {
            $value = $payment[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $evidence[$key] = Str::limit((string) $value, 32, '');
            }
        }

        return $evidence;
    }

    /**
     * A new order reference: unique across every installation sharing the shop
     * (its name first) and never reused - WayForPay refuses a repeated one.
     */
    private function newOrderId(): string
    {
        return strtolower(implode('-', array_filter(['lf', PaymentOrigin::here(), (string) Str::ulid()])));
    }

    /** WayForPay's code for the page language; `AUTO` (the browser's) for any other. */
    private function language(?string $language): string
    {
        $language = strtolower(substr((string) ($language ?? app()->getLocale()), 0, 2));

        return self::LANGUAGES[$language] ?? 'AUTO';
    }

    /** The shop's domain as registered with WayForPay: config, else app.url's host. */
    private function domain(): ?string
    {
        $configured = $this->string(config('larafoundry.credits.purchases.wayforpay.domain'));
        $host = $configured ?? parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Minor units as WayForPay's amount: major units with two decimals. */
    private function major(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** WayForPay's amount (major units, a number or a numeric string) in minor units. */
    private function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
    }

    private function account(): ?string
    {
        return $this->string(config('larafoundry.credits.purchases.wayforpay.merchant_account'));
    }

    private function secretKey(): ?string
    {
        return $this->string(config('larafoundry.credits.purchases.wayforpay.secret_key'));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
