<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Exports the user's tracked login sessions — the device list shown on the
 * profile's "Sessions" tab (phase 5.3).
 *
 * Only the portable fingerprint (device / IP / last activity / login method) is
 * exported. The framework session id is a live credential and is never exported;
 * neither is the per-session PIN-lock state nor the active-company binding —
 * those are operational, not the subject's personal data.
 */
class UserSessionsExporter implements ExportsUserDataProvider
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportFor(Authenticatable $user): array
    {
        if (! method_exists($user, 'sessions')) {
            return [];
        }

        return $user->sessions()
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'device_type' => $session->user_device_type,
                'device_name' => $session->user_device_name,
                'os' => $session->user_os,
                'browser' => $session->user_browser,
                'login_method' => $session->login_method,
                'last_activity' => optional($session->last_activity)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function key(): string
    {
        return 'sessions';
    }

    public function priority(): int
    {
        return 10;
    }
}
