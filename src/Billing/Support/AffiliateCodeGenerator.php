<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;

/**
 * Generates a short, unique, unambiguous referral code (Ф4.4).
 *
 * Uses a crypto-strong source ({@see random_int}) over a base-32 alphabet that drops
 * the visually ambiguous 0/O/1/I, so a code is safe to read aloud or print. Retries on
 * the (vanishingly unlikely) collision against `affiliates.code`, widening the length as
 * a last resort so the loop can never spin forever.
 */
final class AffiliateCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const MAX_ATTEMPTS = 10;

    public function generate(int $length = 8): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->random($length);

            if (! Affiliate::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Astronomically unlikely after 10 tries at 32^8; widen rather than loop forever.
        return $this->random($length + 4);
    }

    private function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
