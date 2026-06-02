<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\ReconcileTrackedSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Manages the user's own tracked login sessions.
 *
 * "Log out other devices" deletes every UserSession row except the caller's
 * current one (the request's own session is preserved so the user stays logged
 * in here). On the database session driver it also drops the framework session
 * rows immediately, evicting other devices on the spot. On other drivers the
 * framework sessions cannot be reached directly, so eviction happens on the
 * other device's next request via
 * {@see ReconcileTrackedSession},
 * which logs out any request whose UserSession row has vanished.
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
        // other devices' framework sessions now. On other drivers the
        // ReconcileTrackedSession middleware evicts them on their next request.
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
