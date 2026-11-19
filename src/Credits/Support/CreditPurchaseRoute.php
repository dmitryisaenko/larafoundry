<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use InvalidArgumentException;

/**
 * How one buyer pays for credits (v0.58.0): through which gateway, in which
 * currency, and with how many extra credits on top of the pack.
 */
final class CreditPurchaseRoute
{
    /** The most a bonus may add, in percent of the pack. */
    public const MAX_BONUS_PERCENT = 100;

    /**
     * @param  string  $gateway  the gateway name purchases are recorded under (`paddle`, `liqpay`)
     * @param  string|null  $currency  ISO 4217 code; null = the main price list's currency
     * @param  int  $bonusPercent  extra credits in percent of the pack, 0-100
     */
    public function __construct(
        public readonly string $gateway,
        public readonly ?string $currency = null,
        public readonly int $bonusPercent = 0,
    ) {
        if ($gateway === '') {
            throw new InvalidArgumentException('A purchase route needs a gateway.');
        }

        if ($bonusPercent < 0 || $bonusPercent > self::MAX_BONUS_PERCENT) {
            throw new InvalidArgumentException('A purchase bonus is 0-'.self::MAX_BONUS_PERCENT.' percent.');
        }
    }

    /**
     * Build a route from a settings entry (`gateway`, `currency`, `bonus_percent`),
     * or null when the entry has no gateway. A bonus out of range is clamped
     * rather than thrown: a typo in a setting must not take payments down.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $gateway = $entry['gateway'] ?? null;

        if (! is_string($gateway) || trim($gateway) === '') {
            return null;
        }

        $currency = $entry['currency'] ?? null;
        $currency = is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;

        $bonus = is_numeric($entry['bonus_percent'] ?? null) ? (int) $entry['bonus_percent'] : 0;

        return new self(trim($gateway), $currency, max(0, min(self::MAX_BONUS_PERCENT, $bonus)));
    }

    /** The credits a buyer gets for a pack of `$credits`, bonus included, rounded down. */
    public function creditsFor(int $credits): int
    {
        return $credits + intdiv($credits * $this->bonusPercent, 100);
    }
}
