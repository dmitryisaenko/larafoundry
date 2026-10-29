<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Gateways;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\SandboxPaymentNotAllowedException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentOrigin;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceList;
use Dmitryisaenko\LaraFoundry\Credits\Exceptions\CreditPurchaseUnavailableException;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Buying credits through Paddle (v0.47.0): one-off transactions at a price the
 * server sets, opened as Paddle.js's overlay on the host's own page.
 *
 * Works whether or not the billing module is switched on: a host with its own
 * notification address passes the verified payload to {@see handle()} (or the
 * request to {@see handleRequest()}); with billing on and the Paddle gateway
 * configured, the core's `/paddle/webhook` route feeds it too.
 *
 * Only the link to Paddle is shared with Cashier-Paddle - the same keys
 * (`larafoundry-billing.paddle.*`, falling back to Cashier's `PADDLE_*`) and its
 * sandbox flag (`cashier.sandbox`, env `PADDLE_SANDBOX`). The purchase itself is
 * recorded by {@see CreditPurchaseService}, never by Cashier's transaction table.
 *
 * Inline pricing: every transaction carries its own amount against ONE catalog
 * product (`larafoundry.credits.purchases.paddle.product_id`), so the host's price
 * list stays the only place prices live.
 *
 * Two guards against test payments leaking into real ones:
 *   - the sandbox flag must agree with the API key's own prefix (`pdl_sdbx_` /
 *     `pdl_live_`) - a half-switched environment records real payments as test
 *     ones or the other way round, silently, so it fails loudly instead;
 *   - while in the sandbox, only the people {@see SandboxTesters} lets through
 *     may open a checkout.
 */
final class PaddleCreditPurchases
{
    /** The gateway name purchases are recorded under. */
    public const GATEWAY = 'paddle';

    /** Written into `custom_data.kind` of every credit transaction. */
    public const KIND = 'larafoundry_credits';

    private const SANDBOX_HOST = 'https://sandbox-api.paddle.com';

    private const LIVE_HOST = 'https://api.paddle.com';

    public function __construct(
        private readonly CreditPurchaseService $purchases,
        private readonly CreditService $credits,
        private readonly CreditPriceList $prices,
        private readonly SandboxTesters $testers,
    ) {}

    public function isSandbox(): bool
    {
        return (bool) config('cashier.sandbox');
    }

    /** Whether a checkout can be opened at all: an API key and a product. */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null && $this->productId() !== null;
    }

    /**
     * Whether this person may pay right now - everyone on live keys, only the
     * testers in the sandbox. A host asks it to decide whether to show the
     * payment form at all.
     */
    public function canPay(?Authenticatable $user): bool
    {
        return ! $this->isSandbox() || $this->testers->allows($user);
    }

    /** The public token Paddle.js authenticates with on the page. */
    public function clientSideToken(): ?string
    {
        return $this->string(config('larafoundry-billing.paddle.client_side_token'))
            ?? $this->string(config('cashier.client_side_token'));
    }

    /**
     * Fail loudly when the sandbox flag disagrees with the API key's prefix. A key
     * of neither shape is left alone - guessing would block a legitimate call.
     */
    public function assertEnvironmentMatchesKey(): void
    {
        $key = (string) $this->apiKey();

        if ($this->isSandbox() && str_starts_with($key, 'pdl_live_')) {
            throw new GatewayNotConfiguredException('Paddle misconfiguration: PADDLE_SANDBOX is on but a LIVE api key is set. Real payments would be recorded as test ones.');
        }

        if (! $this->isSandbox() && str_starts_with($key, 'pdl_sdbx_')) {
            throw new GatewayNotConfiguredException('Paddle misconfiguration: PADDLE_SANDBOX is off but a SANDBOX api key is set. Test payments would be counted as real ones.');
        }
    }

    /**
     * Open a Paddle transaction for `$credits` and record it as a pending
     * purchase. The page passes `transaction_id` to `Paddle.Checkout.open()`;
     * the credits arrive with Paddle's notification, not here.
     *
     * `$customData` is the host's own data for the purchase (strings): it goes to
     * Paddle with the transaction and is kept on the purchase row. `$returnUrl` is
     * sent as the checkout URL only when given - Paddle rejects a domain it has not
     * approved, and without one it uses the account's default payment link.
     *
     * @param  array<string, string>  $customData
     * @return array{transaction_id: string, purchase_id: int, credits: int, price_cents: int, currency: string, client_side_token: string|null, sandbox: bool}
     *
     * @throws SandboxPaymentNotAllowedException
     * @throws CreditPurchaseUnavailableException
     * @throws GatewayNotConfiguredException
     * @throws GatewayOperationException
     */
    public function start(Authenticatable $user, int $credits, array $customData = [], ?string $returnUrl = null): array
    {
        if (! $this->canPay($user)) {
            throw new SandboxPaymentNotAllowedException('Paddle is in sandbox mode and this account may not test payments.');
        }

        $price = $this->prices->priceCents($credits);

        if ($price === null || $price < 1) {
            throw CreditPurchaseUnavailableException::notOnSale($credits);
        }

        $owner = $this->credits->ownerFor($user) ?? throw CreditPurchaseUnavailableException::noPayer();

        if (! $this->isConfigured()) {
            throw new GatewayNotConfiguredException('Paddle is not configured for credit purchases: an API key and larafoundry.credits.purchases.paddle.product_id are required.');
        }

        $this->assertEnvironmentMatchesKey();

        $currency = $this->prices->currency();
        $userId = (int) $user->getAuthIdentifier();

        $payload = [
            'items' => [[
                'quantity' => 1,
                'price' => [
                    'description' => $this->credits->amount($credits),
                    'name' => $this->credits->amount($credits),
                    'product_id' => $this->productId(),
                    // Paddle takes the amount as a STRING of minor units.
                    'unit_price' => ['amount' => (string) $price, 'currency_code' => $currency],
                    // Tax INSIDE the price whatever the account's default: the
                    // confirmation compares the tax-inclusive total with this price,
                    // and with tax on top a discount up to the tax would pass it.
                    'tax_mode' => 'internal',
                    // Exactly one: Paddle lets a buyer change the quantity (1-100 by
                    // default), and a pack bought three times credits once.
                    'quantity' => ['minimum' => 1, 'maximum' => 1],
                ],
            ]],
            // For people reading the transaction in Paddle and for telling payments
            // apart; the confirmation never takes an amount from here.
            'custom_data' => PaymentOrigin::stamp([
                'kind' => self::KIND,
                'user_id' => (string) $userId,
                'credits' => (string) $credits,
            ] + $customData),
        ];

        if ($returnUrl !== null && $returnUrl !== '') {
            $payload['checkout'] = ['url' => $returnUrl];
        }

        $response = Http::withToken((string) $this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->host().'/transactions', $payload);

        if (! $response->successful()) {
            Log::error('larafoundry.credits.paddle.transaction_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new GatewayOperationException('Paddle transaction could not be created.');
        }

        // The transaction id, NOT `data.checkout.url`: that URL points back at the
        // host's own page with `_ptxn`, for Paddle.js to handle there.
        $transactionId = (string) data_get($response->json(), 'data.id', '');

        if ($transactionId === '') {
            Log::error('larafoundry.credits.paddle.transaction_without_id', ['body' => $response->body()]);

            throw new GatewayOperationException('Paddle returned a transaction without an id.');
        }

        $purchase = $this->purchases->open(
            $owner, $credits, $price, $currency, self::GATEWAY, $transactionId,
            userId: $userId, sandbox: $this->isSandbox(), meta: $customData,
        );

        return [
            'transaction_id' => $transactionId,
            'purchase_id' => $purchase->id,
            'credits' => $credits,
            'price_cents' => $price,
            'currency' => $currency,
            'client_side_token' => $this->clientSideToken(),
            'sandbox' => $this->isSandbox(),
        ];
    }

    /**
     * Act on a VERIFIED Paddle notification: a paid transaction credits, an
     * approved refund or chargeback takes back. Everything else - subscriptions,
     * another installation's payments, payments for something other than credits
     * - is left alone, so the host's own handling of the same notification is
     * not disturbed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $type = (string) ($payload['event_type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        // `transaction.paid` too: a deferred payment method can settle through it.
        // Both may arrive for one purchase; the second credits nothing.
        if (in_array($type, ['transaction.completed', 'transaction.paid'], true)) {
            $this->creditFromTransaction($data);
        } elseif (in_array($type, ['adjustment.created', 'adjustment.updated'], true)) {
            $this->refundFromAdjustment($data);
        }
    }

    /**
     * Verify the `Paddle-Signature` of a request and act on it.
     *
     * @throws WebhookVerificationException
     */
    public function handleRequest(Request $request): void
    {
        $this->handle(app(CashierPaddleDriver::class)->verifyWebhook($request));
    }

    /**
     * Credit a settled transaction - against the pending purchase opened for it,
     * never against `custom_data`.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function creditFromTransaction(array $transaction): ?CreditPurchase
    {
        if (! in_array((string) ($transaction['status'] ?? ''), ['completed', 'paid'], true)) {
            return null;
        }

        $custom = (array) ($transaction['custom_data'] ?? []);

        if (($custom['kind'] ?? null) !== self::KIND) {
            return null;
        }

        $reference = (string) ($transaction['id'] ?? '');

        if ($reference === '') {
            return null;
        }

        if (! PaymentOrigin::isOurs($custom)) {
            // Somebody else's - unless this installation opened it, which means the
            // mark is misconfigured (a name set after checkouts were opened, no
            // `install.unmarked`) and paid money is being left uncredited.
            if ($this->purchases->forRefundKey(self::GATEWAY, $reference) !== null) {
                Log::warning('larafoundry.credits.paddle.paid_purchase_marked_elsewhere', [
                    'transaction_id' => $reference,
                    'install' => $custom[PaymentOrigin::KEY] ?? null,
                ]);
            }

            return null;
        }

        // `grand_total` includes tax, and every transaction is opened with the tax
        // inside the price (`tax_mode: internal`), so it compares with our price; a
        // discount brings it below the price and credits nothing.
        $purchase = $this->purchases->confirm(
            self::GATEWAY,
            $reference,
            (int) data_get($transaction, 'details.totals.grand_total', 0),
            (string) ($transaction['currency_code'] ?? ''),
        );

        if ($purchase === null && $this->purchases->forRefundKey(self::GATEWAY, $reference) === null) {
            Log::warning('larafoundry.credits.paddle.unknown_transaction', ['transaction_id' => $reference]);
        }

        return $purchase;
    }

    /**
     * Take back a refunded or charged-back purchase. Paddle models both as an
     * adjustment naming the transaction it reverses. Only an approved one acts (a
     * pending refund has not moved money); a partial refund does not map onto
     * taking credits back and is logged for a person instead.
     *
     * A transaction with no purchase row is not a credit purchase of this
     * installation - every one of those was recorded when its checkout opened.
     *
     * @param  array<string, mixed>  $adjustment
     */
    public function refundFromAdjustment(array $adjustment): ?CreditPurchase
    {
        $transactionId = (string) ($adjustment['transaction_id'] ?? '');

        if ($transactionId === '' || (string) ($adjustment['status'] ?? '') !== 'approved') {
            return null;
        }

        $action = (string) ($adjustment['action'] ?? '');

        if ($this->purchases->forRefundKey(self::GATEWAY, $transactionId) === null) {
            return null;
        }

        if ($action === 'chargeback_reverse') {
            // The dispute went our way and the money came back; the credits taken
            // on the chargeback stay taken. Re-crediting is a decision for a person.
            Log::warning('larafoundry.credits.paddle.chargeback_reversed', ['transaction_id' => $transactionId]);

            return null;
        }

        if (! in_array($action, ['refund', 'chargeback', 'chargeback_warning'], true)) {
            return null;
        }

        // Paddle sends `type: full|partial` on refunds; a chargeback has no type
        // and is always whole. Only an explicit `partial` is partial.
        if ((string) ($adjustment['type'] ?? '') === 'partial') {
            Log::warning('larafoundry.credits.paddle.partial_refund_skipped', ['transaction_id' => $transactionId]);

            return null;
        }

        return $this->purchases->refund(self::GATEWAY, $transactionId, 'paddle.'.$action, keepUnknown: false);
    }

    private function host(): string
    {
        return $this->isSandbox() ? self::SANDBOX_HOST : self::LIVE_HOST;
    }

    private function apiKey(): ?string
    {
        return $this->string(config('larafoundry-billing.paddle.api_key'))
            ?? $this->string(config('cashier.api_key'));
    }

    private function productId(): ?string
    {
        return $this->string(config('larafoundry.credits.purchases.paddle.product_id'));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
