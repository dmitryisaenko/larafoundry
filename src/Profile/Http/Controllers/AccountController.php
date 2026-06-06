<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Profile\Actions\DeleteUserAccount;
use Dmitryisaenko\LaraFoundry\Profile\Http\Requests\DeleteAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * The danger-zone endpoint: a user deletes their own account (phase 5.1).
 *
 * The request has re-authenticated with the current password; the action applies
 * the owner-with-company guard and the (reversible) soft-delete. On success the
 * caller is logged out and their session invalidated, so the now-deleted
 * identity cannot keep browsing. The owner-with-company guard surfaces as a
 * validation error (422), which the frontend shows in the danger zone.
 */
class AccountController extends Controller
{
    public function destroy(DeleteAccountRequest $request, DeleteUserAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        $deleteAccount->delete($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->to((string) config('larafoundry.tenancy.home_route', '/'))
            ->with('status', __('larafoundry::profile.account.deleted'));
    }
}
