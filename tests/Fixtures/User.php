<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Fixtures;

use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Concrete authenticatable model used by the auth + tenancy test-suites.
 *
 * Mirrors how a host model is expected to compose the core: extend Laravel's
 * Authenticatable, implement the locale/verify contracts and pull in the
 * {@see IsLaraFoundryUser} identity trait plus the {@see BelongsToTenancy}
 * tenancy trait, exactly as the trait-slot idiom prescribes (one `use` per
 * phase). `$guarded = []` keeps the tests terse (OAuth/registration paths
 * mass-assign provider + profile columns); the trait helpers are still
 * exercised directly.
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use BelongsToTenancy;
    use IsLaraFoundryUser;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        // Drive serialization hiding through the trait, exactly as the docs
        // tell a host to wire it (so the test proves laraFoundryHidden()).
        $this->hidden = $this->laraFoundryHidden();

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return $this->laraFoundryCasts();
    }
}
