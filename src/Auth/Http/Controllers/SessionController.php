<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Auth\Support\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Manages the user's own tracked login sessions.
 *
 * "Log out other devices" deletes every UserSession row except the caller's
 * current one (the caller's own session is preserved so they stay logged in
 * here) and, on the database session driver, drops the other devices' framework
 * session rows too — which is what actually evicts them. "Revoke this device"
 * does the same for one chosen session. The eviction itself lives in
 * {@see SessionInvalidator}, shared with the email-change flow (phase 5.1).
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

        SessionInvalidator::invalidateOthers($user, $request->session()->getId());

        return back()->with('status', __('larafoundry::auth.sessions.others_logged_out'));
    }

    /**
     * Revoke one specific tracked session of the current user.
     *
     * `{session}` is route-model-bound by id, so it resolves ANY user's row;
     * the ownership check is what closes the IDOR (the donor's delete_session
     * answered 404 on a mismatch — same response here, so an attacker cannot
     * even distinguish a foreign id from a missing one). The current session is
     * not revocable here — that is "log out", a different action — so a probe of
     * the live device cannot self-evict by accident.
     */
    public function destroy(Request $request, UserSession $session): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless((string) $session->user_id === (string) $user->getAuthIdentifier(), 404);

        if ($session->session_id === $request->session()->getId()) {
            return back()->with('status', __('larafoundry::profile.sessions.cannot_revoke_current'));
        }

        SessionInvalidator::invalidateOne($user, $session);

        return back()->with('status', __('larafoundry::profile.sessions.revoked'));
    }
}
