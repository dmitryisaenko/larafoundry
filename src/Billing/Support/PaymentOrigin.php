<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

/**
 * Which installation a payment belongs to (v0.47.0).
 *
 * Several sites may share one gateway account (one code, several installations,
 * each with its own database). A gateway does not sort its notifications by
 * site: each installation's notification address receives every payment of the
 * account. And user ids of different databases run level - "user 11 paid" names
 * a different person in each. Without a mark one payment would be credited in
 * every installation.
 *
 * So whatever opens a payment stamps it ({@see stamp()}, into the gateway's own
 * free-form field - Paddle's `custom_data`, Stripe's `metadata`), and whatever
 * acts on a notification asks {@see isOurs()} first and leaves another
 * installation's payment alone.
 *
 * `larafoundry-billing.install.name` (env `LARAFOUNDRY_INSTALL`) names this
 * installation; unset - nothing is stamped and every payment is ours (one site).
 * `install.unmarked` names the installation an UNSTAMPED payment belongs to - for
 * a host that took payments before it had a mark; unset, an unstamped payment is
 * nobody's once a name is set.
 */
final class PaymentOrigin
{
    /** The field the installation is written under, the same in every gateway. */
    public const KEY = 'install';

    /**
     * The gateway's free-form data for a new payment, with this installation
     * written in.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stamp(array $data): array
    {
        $here = self::here();

        return $here === null ? $data : [self::KEY => $here] + $data;
    }

    /**
     * Whether a notification's free-form data names this installation.
     *
     * @param  array<string, mixed>|object|null  $data
     */
    public static function isOurs(array|object|null $data): bool
    {
        $here = self::here();

        if ($here === null) {
            return true;
        }

        $stamped = is_array($data) ? ($data[self::KEY] ?? null) : ($data->{self::KEY} ?? null);
        $stamped = self::normalise($stamped) ?? self::normalise(config('larafoundry-billing.install.unmarked'));

        return $stamped === $here;
    }

    /** This installation's name, or null when none is set. */
    public static function here(): ?string
    {
        return self::normalise(config('larafoundry-billing.install.name'));
    }

    private static function normalise(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? strtolower(trim($name)) : null;
    }
}
