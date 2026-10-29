<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use Illuminate\Contracts\Auth\Authenticatable;

/** The default: nobody pays through a sandbox until the host says who may. */
final class NoSandboxTesters implements SandboxTesters
{
    public function allows(?Authenticatable $user): bool
    {
        return false;
    }
}
