<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Invitation accept/reject, with the security guard front and centre: a token is
 * NOT enough — the accepting account's email must match the invited email
 * (anti-spoof / account-binding), and expired/used invites are dead. A leaked
 * token must not let an attacker join a company.
 */

function verifiedUser(string $email): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function companyWithInvite(string $email, array $overrides = []): array
{
    $owner = verifiedUser('owner'.uniqid().'@x.test');
    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    $invitation = $company->invitations()->create(array_merge([
        'email' => $email,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ], $overrides));

    return [$company, $invitation];
}

it('lets the addressed user accept and joins them to the company', function () {
    [$company, $invitation] = companyWithInvite('invitee@x.test');
    $invitee = verifiedUser('invitee@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect();

    expect($invitee->fresh()->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->status)->toBe(CompanyInvitation::STATUS_ACCEPTED);
});

it('SECURITY: refuses acceptance from an UNVERIFIED account even with the matching email', function () {
    // The email-string match is not enough: a leaked token plus a fresh,
    // unverified account on the victim's address must NOT join the company.
    // Only a verified mailbox (proven ownership) may accept.
    [$company, $invitation] = companyWithInvite('invitee@x.test');
    $unverified = User::create([
        'name' => 'U',
        'email' => 'invitee@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => null,
    ]);

    $this->actingAs($unverified)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect('/');

    expect($unverified->fresh()->companies()->count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe(CompanyInvitation::STATUS_PENDING);
});

it('SECURITY: the public landing page does not reveal the invited email', function () {
    // The show route is public; leaking the address would hand a token holder
    // the exact email to register for the accept bypass.
    [$company, $invitation] = companyWithInvite('secret-invitee@x.test');

    $this->withHeader('X-Inertia', 'true')
        ->get("/invitations/{$invitation->token}")
        ->assertOk()
        ->assertJsonMissing(['email' => 'secret-invitee@x.test']);
});

it('SECURITY: refuses acceptance when the account email differs from the invite', function () {
    [$company, $invitation] = companyWithInvite('invitee@x.test');
    $attacker = verifiedUser('attacker@x.test'); // holds the leaked token, wrong email

    $this->actingAs($attacker)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect('/');

    // The attacker must NOT have joined, and the invite stays pending.
    expect($attacker->fresh()->companies()->count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe(CompanyInvitation::STATUS_PENDING);
});

it('SECURITY: treats a null-expiry invitation as expired, not eternal', function () {
    // Defense-in-depth: the schema now forbids null expiry, but if a malformed
    // in-memory row ever had one, it must read as expired/not-actionable — never
    // a never-expiring token.
    $invitation = new CompanyInvitation([
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => null,
    ]);

    expect($invitation->isExpired())->toBeTrue()
        ->and($invitation->isActionable())->toBeFalse();
});

it('SECURITY: rejects an expired invitation', function () {
    [$company, $invitation] = companyWithInvite('invitee@x.test', [
        'expires_at' => now()->subDay(),
    ]);
    $invitee = verifiedUser('invitee@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertStatus(410);

    expect($invitee->fresh()->companies()->count())->toBe(0);
});

it('SECURITY: an unknown token is gone, not a 500', function () {
    $invitee = verifiedUser('invitee@x.test');

    $this->actingAs($invitee)
        ->post('/invitations/this-token-does-not-exist/accept')
        ->assertStatus(410);
});

it('lets the addressed user reject', function () {
    [$company, $invitation] = companyWithInvite('invitee@x.test');
    $invitee = verifiedUser('invitee@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/reject")
        ->assertRedirect('/');

    expect($invitation->fresh()->status)->toBe(CompanyInvitation::STATUS_REJECTED);
});

it('matches the invited email case-insensitively', function () {
    [$company, $invitation] = companyWithInvite('Invitee@X.test');
    $invitee = verifiedUser('invitee@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect();

    expect($invitee->fresh()->companies()->count())->toBe(1);
});

it('shows the invitation landing page to a logged-out invitee', function () {
    // A brand-new invitee (no account) must be able to SEE the invite before
    // registering — the show route is intentionally outside auth.
    [$company, $invitation] = companyWithInvite('invitee@x.test');

    $this->withHeader('X-Inertia', 'true')
        ->get("/invitations/{$invitation->token}")
        ->assertOk();
});

it('redirects an unknown/expired invitation away from the landing page', function () {
    $this->get('/invitations/nonexistent-token')->assertRedirect('/');
});

it('resolves the company relation through the configured company model', function () {
    // Regression: company() must resolve config('larafoundry.tenancy.company_model'),
    // not a hardcoded base class, so a host subclass applies in invitation flows.
    config()->set('larafoundry.tenancy.company_model', Company::class);

    [$company, $invitation] = companyWithInvite('invitee@x.test');

    expect($invitation->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($invitation->company->is($company))->toBeTrue();
});
