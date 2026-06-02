<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\PersonalTenantResolver;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\Note;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Personal mode: the tenant is the user itself, so BelongsToTenant filters by
 * user_id (not company_id) and there is no company selection. Proves the same
 * trait/scope works in both modes (decision T3) — including that isolation is
 * still fail-closed for a guest.
 */

beforeEach(function () {
    config()->set('larafoundry.tenancy.mode', 'personal');
    app()->bind(TenantResolver::class, PersonalTenantResolver::class);
});

function personalUser(string $email): User
{
    return User::create(['name' => 'P', 'email' => $email, 'password' => 'secret-pass']);
}

/** Seed a note owned by a user (bypassing scope + non-fillable tenant key). */
function seedUserNote(int $userId, string $body): Note
{
    $note = new Note(['body' => $body]);
    $note->setAttribute('user_id', $userId);
    $note->saveQuietly();

    return $note;
}

it('isolates notes by user_id in personal mode', function () {
    $alice = personalUser('alice@x.test');
    $bob = personalUser('bob@x.test');

    seedUserNote($alice->id, 'a1');
    seedUserNote($alice->id, 'a2');
    seedUserNote($bob->id, 'b1');

    $this->actingAs($alice);

    expect(Note::count())->toBe(2)
        ->and(Note::pluck('body')->all())->toEqualCanonicalizing(['a1', 'a2']);
});

it('auto-fills user_id on create in personal mode', function () {
    $alice = personalUser('alice@x.test');
    $this->actingAs($alice);

    $note = Note::create(['body' => 'mine']);

    expect($note->user_id)->toBe($alice->id)
        ->and($note->company_id)->toBeNull();
});

it('still fails closed for a guest in personal mode', function () {
    seedUserNote(1, 'a');

    // No authenticated user → no tenant → zero rows.
    expect(Note::count())->toBe(0);
});
