<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorizes the email-template editor — super-admin only (phase 5.1).
 *
 * The editor already sits behind the `larafoundry.admin` zone gate (+ OTP) on
 * routes/admin.php. This explicit policy is deliberate (decision D-5.1-3): the
 * email body is HTML rendered into outgoing mail, so its editor does NOT lean on
 * the host's route middleware alone — it re-checks super-admin identity through
 * the same VisitorStatus resolver every other guard uses (a flipped is_admin
 * flag without the reserved email does not pass). Defence-in-depth over the zone
 * gate, never a replacement for it.
 *
 * @param  Model  $user  the host user model
 */
class EmailTemplatePolicy
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

    /**
     * Create (or duplicate into) a marketing template. Same authority as editing;
     * the fail-closed guarantee that a transactional code can never be minted here
     * lives in the repository + FormRequest, not this identity check.
     */
    public function create(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    /**
     * Delete a marketing template. Same authority; the guard that a transactional
     * code can never be deleted lives in the controller + repository.
     */
    public function delete(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    /**
     * Send a test email of a template to an address. Same authority as editing.
     */
    public function test(Model $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    protected function isSuperAdmin(Model $user): bool
    {
        return $user instanceof Authenticatable
            && $this->visitorStatus->isAdmin($user);
    }
}
