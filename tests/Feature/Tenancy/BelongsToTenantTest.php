<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Scopes\TenantScope;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\Note;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * BelongsToTenant is the data-isolation core of phase 1.2. These tests pin the
 * security-critical behaviours: the scope FAILS CLOSED (no tenant → no rows,
 * never all rows), writes auto-bind the tenant key, and that key can't be
 * mass-assigned. The resolver is faked so we control "who the active tenant is"
 * without driving a full session.
 */

/**
 * Bind a fake active tenant (or none) and authenticate a user, so the scope and
 * the create-hook resolve a deterministic tenant key.
 */
function withActiveTenant(?int $companyId): User
{
    $user = User::create(['name' => 'A', 'email' => 'a'.uniqid().'@x.test', 'password' => 'secret-pass']);

    app()->bind(TenantResolver::class, function () use ($companyId) {
        return new class($companyId) implements TenantResolver
        {
            public function __construct(private ?int $companyId) {}

            public function current($user): ?Tenant
            {
                if ($this->companyId === null) {
                    return null;
                }

                $id = $this->companyId;

                return new class($id) implements Tenant
                {
                    public function __construct(private int $id) {}

                    public function getTenantKey(): int|string
                    {
                        return $this->id;
                    }
                };
            }

            public function setCurrent($user, $tenant): void {}

            public function forget($user): void {}
        };
    });

    test()->actingAs($user);

    return $user;
}

/**
 * Seed a note for a specific tenant, bypassing both the scope and the
 * non-mass-assignable tenant key (the product forbids mass-assigning it, so
 * tests must set it explicitly).
 */
function seedNote(int $companyId, string $body): Note
{
    $note = new Note(['body' => $body]);
    $note->setAttribute('company_id', $companyId);
    $note->saveQuietly();

    return $note;
}

it('returns only the active tenant rows', function () {
    // Seed two companies' notes directly (bypass scope) ...
    seedNote(1, 'a');
    seedNote(1, 'b');
    seedNote(2, 'c');

    withActiveTenant(1);

    expect(Note::count())->toBe(2)
        ->and(Note::pluck('body')->all())->toEqualCanonicalizing(['a', 'b']);
});

it('FAILS CLOSED — no active tenant means zero rows, not all rows', function () {
    seedNote(1, 'a');
    seedNote(2, 'b');

    withActiveTenant(null); // authenticated, but no tenant resolves

    expect(Note::count())->toBe(0);
});

it('fails closed for a guest (no auth) too', function () {
    seedNote(1, 'a');

    // No actingAs, no resolver tenant.
    expect(Note::count())->toBe(0);
});

it('auto-fills the tenant key on create from the active tenant', function () {
    withActiveTenant(7);

    $note = Note::create(['body' => 'hello']);

    expect($note->company_id)->toBe(7);
});

it('refuses to create a tenant-scoped row with no active tenant', function () {
    withActiveTenant(null);

    Note::create(['body' => 'orphan']);
})->throws(RuntimeException::class);

it('does not let the tenant key be mass-assigned', function () {
    withActiveTenant(1);

    // company_id is not fillable: a forged value is ignored, the active tenant wins.
    $note = Note::create(['body' => 'x', 'company_id' => 999]);

    expect($note->company_id)->toBe(1);
});

it('forTenant scopes to an explicit tenant, bypassing the active one', function () {
    seedNote(1, 'a');
    seedNote(2, 'b');

    withActiveTenant(1);

    expect(Note::forTenant(2)->pluck('body')->all())->toBe(['b']);
});

it('withoutTenancy lifts isolation entirely', function () {
    seedNote(1, 'a');
    seedNote(2, 'b');

    withActiveTenant(1);

    expect(Note::withoutTenancy()->count())->toBe(2);
});

it('the scope is registered under its class name for explicit removal', function () {
    expect((new Note)->getGlobalScopes())->toHaveKey(TenantScope::class);
});
