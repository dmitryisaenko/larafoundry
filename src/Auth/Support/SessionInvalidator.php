<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Evicts tracked login sessions — the one place "log out a device" is expressed
 * (phase 5.1).
 *
 * Three call sites share it: "log out other devices" (SessionController), "log
 * out this device" (per-session revoke), and an email change (which signs the
 * other devices out so a compromised inbox cannot ride a stale session). Each
 * deletes the matching tracked {@see UserSession} rows AND, on the database
 * session driver, the framework `sessions` rows — which is what actually evicts.
 *
 * NOTE: real remote eviction needs the `database` session driver. On the
 * file/cookie driver the framework session lives outside our reach, so the
 * tracked rows are cleared but the device stays authenticated until it next hits
 * a route TrackSessionActivity guards — a documented limitation (the kohana.io
 * reference host runs the database driver).
 */
class SessionInvalidator
{
    /**
     * Revoke every session of the user except the current one.
     */
    public static function invalidateOthers(Authenticatable $user, string $currentSessionId): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $currentSessionId)
                ->delete();
        }

        if (method_exists($user, 'sessions')) {
            $user->sessions()
                ->where('session_id', '!=', $currentSessionId)
                ->delete();
        }
    }

    /**
     * Revoke one specific tracked session of the user.
     *
     * The caller is responsible for proving ownership of the row first (the
     * per-session route checks `user_id` to close an IDOR — finding from the
     * donor's `delete_session`).
     */
    public static function invalidateOne(Authenticatable $user, UserSession $session): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', $session->session_id)
                ->delete();
        }

        $session->delete();
    }
}
