<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The help editor - super-admin only.
 *
 * The editor sits behind the `larafoundry.admin` zone gate (+ OTP) already; this
 * re-checks identity through the same VisitorStatus every other guard uses, because
 * what is written here is HTML shown to every visitor (mirrors the legal-page and
 * email-template editors). Reading the help is open and not gated by this policy.
 */
class HelpPolicy
{
    public function __construct(private readonly VisitorStatus $visitorStatus) {}

    public function manage(Model $user): bool
    {
        return $user instanceof Authenticatable && $this->visitorStatus->isAdmin($user);
    }
}
