<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorizes the legal-page editor — super-admin only (phase 5.3).
 *
 * The editor already sits behind the `larafoundry.admin` zone gate (+ OTP) on
 * routes/admin.php. This explicit policy mirrors the email-template editor's
 * (decision D-5.1-3): the body is HTML rendered into a public page, so its editor
 * does NOT lean on the route middleware alone — it re-checks super-admin identity
 * through the same VisitorStatus resolver every other guard uses (a flipped
 * is_admin flag without the reserved email does not pass). Defence-in-depth over
 * the zone gate, never a replacement for it. The PUBLIC page rendering is open to
 * everyone and is not gated by this policy.
 *
 * @param  Model  $user  the host user model
 */
class LegalPagePolicy
{
    public function __construct(private readonly VisitorStatus $visitorStatus) {}

    public function viewAny(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function view(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function update(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    protected function isSuperAdmin(Model $user): bool
    {
        return $user instanceof Authenticatable
            && $this->visitorStatus->isAdmin($user);
    }
}
