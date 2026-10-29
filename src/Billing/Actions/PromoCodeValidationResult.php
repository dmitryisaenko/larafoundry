<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Actions;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;

/**
 * The outcome of validating a promo code (sub-phase B5).
 *
 * A small value object shared by the validate endpoint and the subscribe path, so
 * "is this code usable, and what does it grant" lives in one place. The optional
 * `previewDiscount` is the UI estimate from {@see PromoCode::calculateDiscount()} —
 * it is NEVER the charged amount (the gateway computes that); callers that surface
 * it must label it an estimate.
 *
 * The failure shape is deliberately featureless: an invalid result carries no
 * reason, so the endpoint returns a single generic message and never reveals WHICH
 * check failed (anti-enumeration oracle).
 */
final class PromoCodeValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?PromoCode $code = null,
        public readonly ?string $kind = null,
        public readonly ?int $previewDiscount = null,
    ) {}

    public static function invalid(): self
    {
        return new self(false);
    }

    public static function valid(PromoCode $code, ?int $previewDiscount = null): self
    {
        return new self(true, $code, $code->kind, $previewDiscount);
    }
}
