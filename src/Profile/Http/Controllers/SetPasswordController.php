<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Set a first password for an account that has none (v0.49.0) - one created
 * through OAuth.
 *
 * Fortify ships only "change password", which needs the current one - useless to
 * an account without any. This is the missing half, so such a user can also sign
 * in with e-mail and password. Lifted from a production host.
 *
 * Only for accounts WITHOUT a password: one that has a password goes through the
 * change flow (which checks the current one), so a hijacked session cannot use
 * this endpoint to overwrite it.
 *
 * Never on somebody's behalf (review 2026-10-31): an operator signed in as the user
 * would leave with a password of their own to that account, outside every log.
 */
class SetPasswordController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->getAttribute('password') !== null, 403);
        abort_if(app(ActingAsSomeoneElse::class)->active($request), 403);

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min((int) config('larafoundry.auth.password_min_length', 8))->defaults(),
            ],
        ]);

        // The model casts `password => hashed`: the plaintext is hashed once by the
        // cast (Hash::make here would hash it twice).
        $user->forceFill(['password' => $validated['password']])->save();

        // A first password is not a credential change that should sign the user out.
        Auth::guard('web')->setUser($user);

        return back()->with('message-info', __('larafoundry::profile.password.set'));
    }
}
