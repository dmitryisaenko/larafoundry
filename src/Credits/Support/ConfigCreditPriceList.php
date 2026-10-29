<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceList;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditUnitValue;

/**
 * The default price list (v0.47.0), from `larafoundry.credits.purchases`:
 *
 *   - `bundles` - fixed packs, `[['credits' => 5000, 'price_cents' => 969], ...]`;
 *   - `custom.min` - the smallest purchase, bundles included (a cheap bundle must
 *     not slip under a floor a gateway's fixed fee makes loss-making);
 *   - `custom.max` - the largest custom amount; 0 turns custom amounts off;
 *   - `custom.cents_per_credit` - the custom price per credit, by default the
 *     credit's own value (CreditUnitValue). Rounded up to a whole cent.
 */
class ConfigCreditPriceList implements CreditPriceList
{
    public function __construct(private readonly CreditUnitValue $unitValue) {}

    public function priceCents(int $credits): ?int
    {
        // The floor first, bundles included - see the class notes.
        if ($credits < 1 || $credits < $this->min()) {
            return null;
        }

        foreach ($this->allBundles() as $bundle) {
            if ($bundle['credits'] === $credits) {
                return $bundle['price_cents'] > 0 ? $bundle['price_cents'] : null;
            }
        }

        if ($credits > $this->max()) {
            return null;
        }

        // Rounded to 6 places first: 100 * 0.07 is 7.000000000000001 in floats, and
        // a bare ceil() would charge a cent more than the price says.
        $cents = (int) ceil(round($credits * $this->centsPerCredit(), 6));

        return $cents > 0 ? $cents : null;
    }

    public function bundles(): array
    {
        $min = $this->min();

        return array_values(array_filter(
            $this->allBundles(),
            fn (array $bundle): bool => $bundle['credits'] >= $min && $bundle['price_cents'] > 0,
        ));
    }

    public function currency(): string
    {
        return strtoupper((string) config('larafoundry.credits.purchases.currency', 'USD'));
    }

    /** @return list<array{credits: int, price_cents: int}> */
    private function allBundles(): array
    {
        $bundles = [];

        foreach ((array) config('larafoundry.credits.purchases.bundles', []) as $bundle) {
            $credits = is_array($bundle) ? (int) ($bundle['credits'] ?? 0) : 0;

            // The first of two bundles of one size wins - the one priceCents()
            // charges is then also the one bundles() shows.
            if ($credits < 1 || isset($bundles[$credits])) {
                continue;
            }

            $bundles[$credits] = ['credits' => $credits, 'price_cents' => (int) ($bundle['price_cents'] ?? 0)];
        }

        ksort($bundles);

        return array_values($bundles);
    }

    private function min(): int
    {
        return max(1, (int) config('larafoundry.credits.purchases.custom.min', 1));
    }

    private function max(): int
    {
        return (int) config('larafoundry.credits.purchases.custom.max', 0);
    }

    private function centsPerCredit(): float
    {
        $configured = config('larafoundry.credits.purchases.custom.cents_per_credit');

        return is_numeric($configured) ? (float) $configured : $this->unitValue->cents();
    }
}
