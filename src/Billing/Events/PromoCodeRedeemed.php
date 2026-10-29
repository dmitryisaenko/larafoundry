<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Events;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a promo code is redeemed (sub-phase B5).
 *
 * Dispatched by the redemption actions after the usage counter and the redemption
 * ledger row are committed, so a listener (analytics, notification) sees a code
 * that genuinely counted. `$userId` is the authenticated redeemer, or null for a
 * system-driven grant.
 */
class PromoCodeRedeemed
{
    use Dispatchable;

    public function __construct(
        public readonly PromoCode $code,
        public readonly Tenant $company,
        public readonly ?int $userId = null,
    ) {}
}
