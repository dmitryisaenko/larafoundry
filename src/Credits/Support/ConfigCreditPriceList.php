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
 *
 * Since v0.58.0 the same reader serves a list in another currency
 * (`larafoundry.credits.purchases.currencies.<CODE>`, see ConfigCreditPriceLists).
 * Such a list never falls back to the credit's value - that is in the main
 * currency - so its custom amounts are on sale only with its own
 * `cents_per_credit`.
 */
class ConfigCreditPriceList implements CreditPriceList
{
    /**
     * @param  array<string, mixed>|null  $settings  another currency's list; null = the main one
     */
    public function __construct(
        private readonly CreditUnitValue $unitValue,
        private readonly ?array $settings = null,
        private readonly ?string $currencyCode = null,
    ) {}

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

        $perCredit = $this->centsPerCredit();

        if ($perCredit === null) {
            return null;
        }

        // Rounded to 6 places first: 100 * 0.07 is 7.000000000000001 in floats, and
        // a bare ceil() would charge a cent more than the price says.
        $cents = (int) ceil(round($credits * $perCredit, 6));

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
        return strtoupper($this->currencyCode ?? (string) $this->setting('currency', 'USD'));
    }

    /** @return list<array{credits: int, price_cents: int}> */
    private function allBundles(): array
    {
        $bundles = [];

        foreach ((array) $this->setting('bundles', []) as $bundle) {
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
        return max(1, (int) $this->setting('custom.min', 1));
    }

    private function max(): int
    {
        return (int) $this->setting('custom.max', 0);
    }

    private function centsPerCredit(): ?float
    {
        $configured = $this->setting('custom.cents_per_credit');

        if (is_numeric($configured)) {
            return (float) $configured;
        }

        return $this->settings === null ? $this->unitValue->cents() : null;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings === null
            ? config('larafoundry.credits.purchases.'.$key, $default)
            : data_get($this->settings, $key, $default);
    }
}
