<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

/**
 * The single source of truth for "should the admin-access alert fire?".
 *
 * Both the core's mail listener and any host-added channel listener (Telegram,
 * Slack, ...) ask this one helper, so the three configurable axes —
 *
 *   - `notify_admin` (master on/off switch),
 *   - `alert_on`     (which failure TYPES raise an alert, e.g. ['admin_otp']),
 *   - `channels`     (which CHANNELS deliver, the core knows 'mail'; a host
 *                     appends its own, e.g. 'telegram'),
 *
 * live in exactly one place and the "failure type × channel" matrix never
 * drifts between channels.
 *
 * A host channel listener guards itself with, e.g.:
 *
 *     if (! AdminAccessAlertPolicy::shouldAlert($event->step, 'telegram')) {
 *         return;
 *     }
 *
 * and the operator opts that channel in by adding 'telegram' to the
 * `larafoundry.auth.failed_login.channels` config array.
 */
class AdminAccessAlertPolicy
{
    /**
     * Whether an alert should be delivered for the given failure step over the
     * given channel, per the configured master switch, type filter and channel
     * allow-list.
     */
    public static function shouldAlert(string $step, string $channel): bool
    {
        if (! config('larafoundry.auth.failed_login.notify_admin', false)) {
            return false;
        }

        return self::stepEnabled($step) && self::channelEnabled($channel);
    }

    /**
     * Whether the given failure type is allowed to raise an alert at all.
     */
    public static function stepEnabled(string $step): bool
    {
        return in_array($step, self::arrayConfig('alert_on', [
            'password', 'lockout', 'admin_otp', 'pin',
        ]), true);
    }

    /**
     * Whether the given delivery channel is opted in.
     */
    public static function channelEnabled(string $channel): bool
    {
        return in_array($channel, self::arrayConfig('channels', ['mail']), true);
    }

    /**
     * Read an array config key, tolerating a non-array value by falling back.
     *
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    protected static function arrayConfig(string $key, array $default): array
    {
        $value = config('larafoundry.auth.failed_login.'.$key, $default);

        return is_array($value) ? $value : $default;
    }
}
