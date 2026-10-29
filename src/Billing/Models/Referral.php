<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The attribution snapshot binding a referred tenant to its affiliate (Ф4.4).
 *
 * Written once when the tenant appears: on the core's CompanyCreated event in `teams`
 * mode, on user registration in `personal` (AttributeReferral owns both).
 * `referred_company_id` holds the TENANT KEY — a company id or, in `personal` mode, the
 * referred user's id — and is unique at the schema level: one tenant, one referrer,
 * locked once. The name is kept for backward compatibility; read it as
 * "referred_tenant_id". `window_ends_at` bounds the recurring-commission window (null =
 * open: first_payment / lifetime triggers do not bound by date here).
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $referred_company_id the referred tenant key (company id, or user id in personal mode)
 * @property string $source_code
 * @property Carbon $attributed_at
 * @property Carbon|null $window_ends_at
 */
class Referral extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliate_id',
        'referred_company_id',
        'source_code',
        'attributed_at',
        'window_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'attributed_at' => 'datetime',
            'window_ends_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * The referred tenant — the host's configured billable model (its Company subclass
     * in `teams` mode, its User in `personal`), else the core Company. The add-on does
     * not own the host's model.
     */
    public function company(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('larafoundry-billing.billable_model') ?: Company::class;

        return $this->belongsTo($model, 'referred_company_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    /**
     * Whether a payment made at `$at` falls inside the recurring window. An open window
     * (null) always qualifies — the date axis is the recurring trigger's only concern;
     * first_payment / lifetime triggers leave it null and decide by other means.
     */
    public function isWithinWindow(Carbon $at): bool
    {
        return $this->window_ends_at === null || $at->lessThanOrEqualTo($this->window_ends_at);
    }
}
