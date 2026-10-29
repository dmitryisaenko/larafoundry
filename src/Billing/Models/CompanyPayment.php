<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as DefaultUser;
use Illuminate\Support\Carbon;

/**
 * A recorded payment against a company's subscription (sub-phase B7a).
 *
 * Ported from the donor's `App\Models\CompanyPayment`, with the add-on's money and
 * cross-package conventions:
 *
 *  - `amount` / `discount_amount` are whole MINOR UNITS (cents), not the donor's
 *    `decimal:2` dollars. The gateway is the source of truth for every charge and
 *    reports cents on the webhook; the core's `PlanContract::priceFor()` is also cents.
 *  - `payment_response` is reduced to an ALLOWLISTED safe subset on write (see the
 *    mutator below): a payment log must never persist card numbers, names, emails,
 *    addresses or card fingerprints. Writes MUST go through the model — Eloquent
 *    mutators do not run on query-builder / bulk `update`, so the payment listener
 *    (B7b) writes via a model instance.
 *  - relations resolve the host's models from config (`billable_model` / the auth user
 *    model), never a hardcoded host class — the add-on does not own them.
 *
 * The `payments` relation on Company is registered by the service provider via
 * `resolveRelationUsing`, so the core model stays untouched.
 *
 * `company_id` holds the TENANT KEY, not necessarily a company id: in `teams` mode it
 * is the company, in `personal` mode the paying USER (there are no companies at all).
 * The column keeps its name — renaming it would break every installed host — so read it
 * as "tenant_id"; {@see BillingSubject::key()}
 * is what writes it, and the `company()` relation resolves whichever billable model the
 * host configured, so it points at a User in `personal` mode.
 *
 * @property int $id
 * @property int $company_id the tenant key: a company id (teams) or a user id (personal)
 * @property int|null $user_id
 * @property string $plan_id
 * @property string $billing_period
 * @property int $amount
 * @property string $currency
 * @property int $discount_amount
 * @property string|null $discount_reason
 * @property int|null $promo_code_id
 * @property string $payment_status
 * @property string|null $payment_method
 * @property string|null $gateway_reference
 * @property array<array-key, mixed>|null $payment_response
 * @property Carbon|null $paid_at
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 */
class CompanyPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    /**
     * The ONLY keys kept from a gateway payload before it is stored — an allowlist,
     * not a denylist.
     *
     * A denylist of "sensitive" names is structurally unsafe for a payment log: a PSP
     * nests PII under many key names (`receipt_email`, `fingerprint`,
     * `billing_details.name`, `address.line1`, …) and `number` means an invoice number
     * in one place and a card PAN in another. So we keep ONLY keys known to be safe —
     * identifiers, money, status, card brand metadata (NOT the PAN; `last4` is non-PII
     * per PCI) and failure diagnostics — and drop everything else, at every depth.
     * Match is case-insensitive. Defence in depth: the listener also passes a curated
     * payload, but the model guarantees nothing sensitive lands at rest.
     *
     * @var list<string>
     */
    private const SAFE_RESPONSE_KEYS = [
        // Identifiers / linkage (ids are not PII).
        'gateway', 'event', 'event_id', 'id', 'object', 'type',
        'invoice', 'subscription', 'subscription_id', 'customer', 'customer_id',
        'payment_intent', 'charge', 'transaction_id', 'order_id', 'checkout_id',
        // Money / status.
        'status', 'paid', 'amount', 'amount_paid', 'amount_due', 'amount_total',
        'subtotal', 'total', 'tax', 'discount', 'discount_amount', 'currency',
        'currency_code', 'created', 'created_at', 'period_start', 'period_end',
        'billing_reason', 'collection_method', 'attempt_count',
        // Plan / product (non-PII).
        'plan', 'plan_id', 'price', 'price_id', 'product', 'interval', 'quantity',
        // Card brand metadata — never the PAN; last4 is non-PII per PCI.
        'brand', 'last4', 'exp_month', 'exp_year', 'card_type', 'payment_method_type',
        'funding', 'wallet',
        // Failure diagnostics (non-PII).
        'failure_code', 'failure_message', 'decline_code', 'outcome',
        // Links / references (non-PII).
        'hosted_invoice_url', 'receipt_number',
    ];

    /**
     * Depth bound for {@see safeSubset()} — drops anything nested deeper, so a hostile
     * or `expand`-bloated payload cannot drive unbounded recursion or table growth.
     */
    private const MAX_RESPONSE_DEPTH = 12;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'plan_id',
        'billing_period',
        'amount',
        'currency',
        'discount_amount',
        'discount_reason',
        'promo_code_id',
        'payment_status',
        'payment_method',
        'gateway_reference',
        'payment_response',
        'paid_at',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            // Whole minor units (cents), not the donor's decimal:2.
            'amount' => 'integer',
            'discount_amount' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /**
     * The gateway payload: reduced to a safe subset and JSON-encoded on write, decoded
     * on read.
     *
     * This Attribute REPLACES an `array` cast on purpose — routing every write through
     * {@see safeSubset()} means the allowlist cannot be bypassed by mass assignment or
     * a direct `payment_response =` set on the model. The encode flags keep a bad
     * payload from silently becoming the literal `false`: invalid UTF-8 is substituted
     * and a partial encode is preferred over losing the whole log row.
     */
    protected function paymentResponse(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true),
            set: fn (?array $value): ?string => $value === null
                ? null
                : json_encode(
                    self::safeSubset($value),
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
        );
    }

    /**
     * Reduce a gateway payload to its allowlisted, depth-bounded safe subset.
     *
     * A string key survives only if it is in {@see SAFE_RESPONSE_KEYS}; everything else
     * is dropped (never stored). Numeric keys are list indices, not field names, so
     * they are always traversed — that filters each element of a list of objects (e.g.
     * `charges.data[]`). Nesting past {@see MAX_RESPONSE_DEPTH} is dropped wholesale.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function safeSubset(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_RESPONSE_DEPTH) {
            return [];
        }

        $safe = [];

        foreach ($data as $key => $value) {
            // String keys must be allowlisted; numeric (list) indices always pass.
            if (! is_int($key) && ! in_array(strtolower((string) $key), self::SAFE_RESPONSE_KEYS, true)) {
                continue;
            }

            $safe[$key] = is_array($value) ? self::safeSubset($value, $depth + 1) : $value;
        }

        return $safe;
    }

    /**
     * The paying subject this row belongs to: the host's Billable Company subclass in
     * `teams` mode, its User class in `personal` — whichever `billable_model` names.
     */
    public function company(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('larafoundry-billing.billable_model') ?: Company::class;

        return $this->belongsTo($model, 'company_id');
    }

    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        return $this->belongsTo($model, 'user_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * Net charge in minor units (cents), never negative.
     */
    public function getTotalAmount(): int
    {
        return max(0, $this->amount - $this->discount_amount);
    }

    public function markAsPaid(?array $response = null): void
    {
        $this->update([
            'payment_status' => self::STATUS_SUCCESS,
            'paid_at' => now(),
            'payment_response' => $response,
        ]);
    }

    public function markAsFailed(?array $response = null): void
    {
        $this->update([
            'payment_status' => self::STATUS_FAILED,
            'payment_response' => $response,
        ]);
    }

    public function isSuccessful(): bool
    {
        return $this->payment_status === self::STATUS_SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->payment_status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->payment_status === self::STATUS_FAILED;
    }
}
