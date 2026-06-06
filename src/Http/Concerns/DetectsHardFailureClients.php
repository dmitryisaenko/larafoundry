<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Shared "should this guard hard-fail or redirect?" predicate.
 *
 * The auth/console guards (super-admin confine, OTP step-up, PIN-lock) all face
 * the same choice when they refuse a request: a pure JSON/XHR client cannot
 * follow an HTML redirect, so it must get a hard status (403/423); Inertia
 * (which carries X-Inertia) and plain browser navigations DO follow the
 * redirect. Keeping the rule in one place stops the three guards from drifting.
 */
trait DetectsHardFailureClients
{
    protected function expectsHardFailure(Request $request): bool
    {
        return $request->expectsJson() && ! $request->headers->has('X-Inertia');
    }
}
