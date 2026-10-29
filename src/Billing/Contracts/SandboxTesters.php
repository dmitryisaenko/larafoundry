<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

use Dmitryisaenko\LaraFoundry\Billing\Support\NoSandboxTesters;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may pay while a gateway runs in its sandbox (v0.47.0).
 *
 * A sandbox checkout takes no real money, so on a live site it must be reachable
 * only to the people testing it - otherwise a customer could "pay", be charged
 * nothing and either receive nothing or receive credits for free. Asked only
 * while the gateway is in sandbox mode; on live keys everyone may pay and this is
 * never consulted, so there is nothing to remember to switch back.
 *
 * The default, {@see NoSandboxTesters}, lets nobody through; a host binds its own
 * (an operator-set flag on the account, a list of emails).
 */
interface SandboxTesters
{
    public function allows(?Authenticatable $user): bool;
}
