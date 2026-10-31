<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Support;

use Closure;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Illuminate\Http\Request;

/**
 * Whether the person behind this request is an operator signed in as somebody else
 * (v0.49.0).
 *
 * The core's own impersonation always counts; a host with a way of its own to act
 * as a user adds it with `addCheck()`, once, at boot. Whatever acts on the user's
 * own account and must never be done on their behalf - set a first password, finish
 * or reopen their setup wizard - asks here, so one answer covers every such way.
 *
 * Registered as a singleton, so checks added at boot hold.
 */
class ActingAsSomeoneElse
{
    /**
     * @var array<int, Closure>
     */
    protected array $checks = [];

    /**
     * @param  Closure(Request): bool  $check
     */
    public function addCheck(Closure $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    public function active(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        if ($request->session()->get(ImpersonateController::SESSION_KEY) !== null) {
            return true;
        }

        foreach ($this->checks as $check) {
            if ($check($request)) {
                return true;
            }
        }

        return false;
    }
}
