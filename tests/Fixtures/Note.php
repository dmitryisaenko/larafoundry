<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Fixtures;

use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal tenant-scoped domain model for exercising {@see BelongsToTenant}.
 *
 * Stands in for a host's real domain model (orders, products, …): it carries a
 * tenant foreign key and pulls in the trait, so the suite can prove the scope
 * fails closed, auto-fills the tenant key on create, and refuses mass-assignment
 * of that key. `body` is mass-assignable; the tenant column is NOT.
 */
class Note extends Model
{
    use BelongsToTenant;

    protected $table = 'notes';

    protected $fillable = ['body'];
}
