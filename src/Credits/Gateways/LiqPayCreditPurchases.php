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
 * Buying credits through LiqPay (v0.58.0), the Ukrainian acquirer: the buyer is
 * sent to LiqPay's own payment page by a form the server signs, and LiqPay tells
 * the server the outcome at `server_url`.
 *
 * Only buyers whose country {@see CreditPurchaseRoutes} sends to this gateway may
 * open a checkout - checked here, not only on the page, so the address cannot be
 * reached around the page. The price comes from the price list of the route's
 * currency ({@see CreditPriceLists}), the route's bonus is added to the pack, and
 * the pending purchase is written with both before the buyer leaves - the
 * confirmation credits what the ROW says, never what came with the payment.
 *
 * Protocol (liqpay.ua/doc, checked 2026-11-19): `data` is base64 of the JSON
 * parameters, `signature` is base64 of the RAW sha3-256 of
 * `private_key . data . private_key`, `version` 7. The documentation's own code
 * samples still show SHA-1 with version 3; its worked example matches sha3-256
 * only. A notification is accepted when either digest matches - both need the
 * private key, so neither lets a stranger forge one. `amount` is in major units.
 *
 * Statuses: `success` and `wait_compensation` (paid, settled in the daily
 * payout) credit; `reversed` takes the credits back (a partial refund is logged
 * and left to a person); `wait_accept` (money taken but the shop not yet
 * approved by LiqPay - cancelled after 60 days if it never is) credits nothing
 * and is logged; anything else is not a payment.
 *
 * Two guards against test payments, as for Paddle:
 *   - a test key pair always starts with `sandbox_`, and that prefix IS the
 *     sandbox switch; a public and a private key from different pairs fail
 *     loudly instead of recording real payments as test ones or the other way;
 *   - in the sandbox only the people {@see SandboxTesters} lets through may pay.
 *
 * LiqPay is told nothing about which installation a payment belongs to: every
 * checkout names its own `server_url`, and the order id carries the
 * installation's name so ids of several sites on one LiqPay shop never collide.
 * A notification for an order this installation has no row for is left alone.
 */
final class LiqPayCreditPurchases
{
    /** The gateway name purchases are recorded under. */
    public const GATEWAY = 'liqpay';

    public const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';

    public const API_URL = 'https://www.liqpay.ua/api/request';

    public const VERSION = 7;

    private const SANDBOX_PREFIX = 'sandbox_';

    /** Statuses that mean the money is the shop's. */
    private const PAID = ['success', 'wait_compensation'];

    /** The languages LiqPay's payment page speaks. */
    private const LANGUAGES = ['uk', 'en'];

    public function __construct(
        private readonly CreditPurchaseService $purchases,
        private readonly CreditService $credits,
        private readonly CreditPriceLists $prices,
        private readonly CreditPurchaseRoutes $routes,
        private readonly SandboxTesters $testers,
    ) {}

    /** Whether a checkout can be opened at all: both keys. */
    public function isConfigured(): bool
    {
        return $this->publicKey() !== null && $this->privateKey() !== null;
    }

    /** A test key pair: LiqPay's test keys always start with `sandbox_`. */
    public function isSandbox(): bool
    {
        return str_starts_with((string) $this->publicKey(), self::SANDBOX_PREFIX);
    }

    /**
     * Whether this person may pay right now - everyone on live keys, only the
     * testers on test keys.
     */
    public function canPay(?Authenticatable $user): bool
    {
        return ! $this->isSandbox() || $this->testers->allows($user);
    }

    /**
     * Fail loudly when the public and the private key come from different pairs:
     * the public key decides whether a purchase is marked a test one, the private
     * key decides which shop takes the money.
     */
    public function assertKeysAgree(): void
    {
        $public = str_starts_with((string) $this->publicKey(), self::SANDBOX_PREFIX);
        $private = str_starts_with((string) $this->privateKey(), self::SANDBOX_PREFIX);

        if ($public !== $private) {
            throw new GatewayNotConfiguredException('LiqPay misconfiguration: the public and the private key come from different pairs (one starts with "sandbox_", the other does not).');
        }
    }

    /**
     * Open a checkout for a pack of `$credits` for a buyer connecting from
     * `$country`, and record it as a pending purchase. The page posts `data` and
     * `signature` to `url` (a plain form, LiqPay's own page); the credits arrive
     * with LiqPay's notification at `$serverUrl`, not here.
     *
     * The buyer gets the pack plus the route's bonus (`credits` in the result,
     * `bonus_credits` of them extra) for the pack's price in the route's currency.
     *
     * `$customData` is the host's own data for the purchase, kept on the row; the
     * gateway adds its own under `liqpay`. `$receiptEmail` goes into the fiscal
     * receipt data when `liqpay.rro_product_id` is set.
     *
     * @param  array<string, mixed>  $customData
     * @return array{url: string, data: string, signature: string, order_id: string, purchase_id: int, credits: int, bonus_credits: int, price_cents: int, currency: string, sandbox: bool}
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
        ?string $resultUrl = null,
        ?string $serverUrl = null,
        ?string $language = null,
        ?string $receiptEmail = null,
    ): array {
        $route = $this->routes->for($country);

        if ($route->gateway !== self::GATEWAY) {
            throw CreditPurchaseUnavailableException::notForCountry(self::GATEWAY, $country);
        }

        if (! $this->canPay($user)) {
            throw new SandboxPaymentNotAllowedException('LiqPay is on test keys and this account may not test payments.');
        }

        $prices = $this->prices->in($route->currency);
        $price = $prices?->priceCents($credits);

        if ($prices === null || $price === null || $price < 1) {
            throw CreditPurchaseUnavailableException::notOnSale($credits);
        }

        $owner = $this->credits->ownerFor($user) ?? throw CreditPurchaseUnavailableException::noPayer();

        if (! $this->isConfigured()) {
            throw new GatewayNotConfiguredException('LiqPay is not configured for credit purchases: larafoundry.credits.purchases.liqpay.public_key and private_key are required.');
        }

        $this->assertKeysAgree();

        $currency = $prices->currency();
        $total = $route->creditsFor($credits);
        $orderId = $this->newOrderId();
        $amount = $this->major($price);

        $params = array_filter([
            'version' => self::VERSION,
            'public_key' => $this->publicKey(),
            'action' => 'pay',
            'amount' => $amount,
            'currency' => $currency,
            'description' => $this->credits->amount($total),
            'order_id' => $orderId,
            'language' => $this->language($language),
            'result_url' => $resultUrl,
            'server_url' => $serverUrl,
            'rro_info' => $this->receipt($amount, $receiptEmail),
        ], fn ($value) => $value !== null && $value !== '');

        $data = base64_encode((string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
            'data' => $data,
            'signature' => $this->sign($data),
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
     * Verify a notification's signature and act on it.
     *
     * @throws WebhookVerificationException
     */
    public function handleRequest(Request $request): ?CreditPurchase
    {
        $data = $request->input('data');
        $signature = $request->input('signature');

        if (! is_string($data) || ! is_string($signature) || ! $this->verify($data, $signature)) {
            throw new WebhookVerificationException('LiqPay notification signature mismatch.');
        }

        $decoded = base64_decode($data, true);
        $payment = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($payment)) {
            throw new WebhookVerificationException('LiqPay notification data is not JSON.');
        }

        return $this->handle($payment);
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
        $orderId = (string) ($payment['order_id'] ?? '');

        if ($orderId === '') {
            return null;
        }

        $publicKey = (string) ($payment['public_key'] ?? '');

        if ($publicKey !== '' && $publicKey !== $this->publicKey()) {
            // Another shop's payment, or keys switched since the checkout opened.
            Log::warning('larafoundry.credits.liqpay.foreign_public_key', ['order_id' => $orderId]);

            return null;
        }

        $purchase = $this->purchases->forRefundKey(self::GATEWAY, $orderId);

        if ($purchase === null) {
            Log::warning('larafoundry.credits.liqpay.unknown_order', [
                'order_id' => $orderId,
                'status' => $payment['status'] ?? null,
            ]);

            return null;
        }

        $status = (string) ($payment['status'] ?? '');

        if (in_array($status, self::PAID, true)) {
            return $this->purchases->confirm(
                self::GATEWAY,
                $orderId,
                $this->cents($payment['amount'] ?? null),
                (string) ($payment['currency'] ?? ''),
                [self::GATEWAY => $this->evidence($payment)],
            );
        }

        if ($status === 'reversed') {
            return $this->refund($purchase, $payment);
        }

        $context = [
            'purchase_id' => $purchase->id,
            'order_id' => $orderId,
            'status' => $status,
            'err_code' => $payment['err_code'] ?? null,
        ];

        if ($status === 'wait_accept') {
            // Money taken, but the shop is not approved by LiqPay yet: it may still
            // be cancelled, so nothing is credited. A shop must be approved before
            // it goes live.
            Log::warning('larafoundry.credits.liqpay.wait_accept', $context);
        } else {
            Log::info('larafoundry.credits.liqpay.not_paid', $context);
        }

        return null;
    }

    /**
     * Ask LiqPay how a pending purchase stands and act on the answer - for a
     * notification that never arrived (the site was down, LiqPay gave up). Null
     * when nothing moved, LiqPay could not be asked, or it knows no such order (a
     * checkout the buyer never paid).
     */
    public function sync(CreditPurchase $purchase): ?CreditPurchase
    {
        if ($purchase->gateway !== self::GATEWAY || ! $purchase->isPending() || ! $this->isConfigured()) {
            return null;
        }

        $reference = (string) $purchase->reference;
        $data = base64_encode((string) json_encode([
            'version' => self::VERSION,
            'public_key' => $this->publicKey(),
            'action' => 'status',
            'order_id' => $reference,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $response = Http::asForm()->timeout(20)->post(self::API_URL, [
                'data' => $data,
                'signature' => $this->sign($data),
            ]);
        } catch (Throwable $e) {
            Log::warning('larafoundry.credits.liqpay.status_failed', ['order_id' => $reference, 'error' => $e->getMessage()]);

            return null;
        }

        $answer = $response->successful() ? $response->json() : null;

        if (! is_array($answer) || ($answer['result'] ?? null) === 'error') {
            return null;
        }

        // The answer comes straight from LiqPay over TLS, to a request we signed;
        // it names the order we asked about.
        return $this->handle(['order_id' => $reference] + $answer);
    }

    /** The signature LiqPay expects for `$data`. */
    public function sign(string $data): string
    {
        $key = (string) $this->privateKey();

        return base64_encode(hash('sha3-256', $key.$data.$key, true));
    }

    /** Whether `$signature` is LiqPay's for `$data` (sha3-256 or, as older code signs, sha1). */
    public function verify(string $data, string $signature): bool
    {
        $key = $this->privateKey();

        if ($key === null || $data === '' || $signature === '') {
            return false;
        }

        foreach (['sha3-256', 'sha1'] as $algorithm) {
            if (hash_equals(base64_encode(hash($algorithm, $key.$data.$key, true)), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take back a refunded purchase. A partial refund does not map onto taking
     * credits back and is logged for a person instead.
     *
     * @param  array<string, mixed>  $payment
     */
    private function refund(CreditPurchase $purchase, array $payment): ?CreditPurchase
    {
        $refunded = $payment['refund_amount'] ?? null;

        if (is_numeric($refunded)) {
            $cents = $this->cents($refunded);

            if ($cents > 0 && $cents < $purchase->amount_cents) {
                Log::warning('larafoundry.credits.liqpay.partial_refund_skipped', [
                    'purchase_id' => $purchase->id,
                    'refunded_cents' => $cents,
                    'amount_cents' => $purchase->amount_cents,
                ]);

                return null;
            }
        }

        return $this->purchases->refund(self::GATEWAY, (string) $purchase->refund_key, 'liqpay.reversed', keepUnknown: false);
    }

    /**
     * What the payment report says about where the money came from - the card's
     * country (numeric ISO 3166-1, `804` is Ukraine) beside the connection
     * country recorded at the checkout: the two pieces of evidence of where the
     * buyer is.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, string>
     */
    private function evidence(array $payment): array
    {
        $evidence = [];

        foreach (['card_country' => 'sender_card_country', 'card_bank' => 'sender_card_bank', 'paytype' => 'paytype'] as $key => $field) {
            $value = $payment[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $evidence[$key] = Str::limit((string) $value, 32, '');
            }
        }

        return $evidence;
    }

    /**
     * The fiscal receipt data: one line, the product registered in the LiqPay
     * cabinet, at the purchase's price. Null without that product - LiqPay cannot
     * build a receipt without it.
     *
     * @return array<string, mixed>|null
     */
    private function receipt(string $amount, ?string $email): ?array
    {
        $product = config('larafoundry.credits.purchases.liqpay.rro_product_id');

        if (! is_numeric($product) || (int) $product < 1) {
            return null;
        }

        $price = (float) $amount;
        $receipt = ['items' => [['amount' => 1, 'price' => $price, 'cost' => $price, 'id' => (int) $product]]];

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $receipt['delivery_emails'] = [$email];
        }

        return $receipt;
    }

    /**
     * A new order id: unique across every installation sharing the LiqPay shop
     * (its name first) and never reused.
     */
    private function newOrderId(): string
    {
        return strtolower(implode('-', array_filter(['lf', PaymentOrigin::here(), (string) Str::ulid()])));
    }

    private function language(?string $language): string
    {
        $language = strtolower(substr((string) ($language ?? app()->getLocale()), 0, 2));

        return in_array($language, self::LANGUAGES, true) ? $language : 'en';
    }

    /** Minor units as LiqPay's amount: major units with two decimals. */
    private function major(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** LiqPay's amount (major units, a number or a numeric string) in minor units. */
    private function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
    }

    private function publicKey(): ?string
    {
        return $this->string(config('larafoundry.credits.purchases.liqpay.public_key'));
    }

    private function privateKey(): ?string
    {
        return $this->string(config('larafoundry.credits.purchases.liqpay.private_key'));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
