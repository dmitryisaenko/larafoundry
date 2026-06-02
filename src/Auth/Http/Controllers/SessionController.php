<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Manages the user's own tracked login sessions.
 *
 * "Log out other devices" deletes every UserSession row except the caller's
 * current one (the caller's own session is preserved so they stay logged in
 * here) and, on the database session driver, drops the other devices' framework
 * session rows too — which is what actually evicts them.
 *
 * NOTE: genuine remote eviction requires the `database` session driver. On the
 * file/cookie driver the framework session lives outside our reach, so a revoked
 * device stays authenticated (and TrackSessionActivity simply re-creates its
 * tracking row on its next request). Hosts that need this feature should run the
 * database session driver — the kohana.io reference host does.
 */
class SessionController extends Controller
{
    /**
     * Revoke every session except the current request's.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'sessions')) {
            abort(403);
        }

        $currentId = $request->session()->getId();

        // Best-effort immediate eviction: on the database driver we can delete
        // other devices' framework sessions now, so they are killed at once. On
        // other drivers we cannot reach those sessions, so "log out other
        // devices" is a documented limitation there — the framework session
        // itself is not destroyed, only the tracked UserSession rows below.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $currentId)
                ->delete();
        }

        $user->sessions()
            ->where('session_id', '!=', $currentId)
            ->delete();

        return back()->with('status', __('larafoundry::auth.sessions.others_logged_out'));
    }
}
