<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/*
 * The 3-step creation wizard (no billing step). Step 1 must create the company,
 * make the user its owner, and fire CompanyCreated (the phase-1.3 hook). Step 3
 * queues invitations. We mostly drive the action directly (the HTTP layer is a
 * thin wrapper) and assert the side effects.
 */

function creator(): User
{
    return User::create([
        'name' => 'Owner',
        'email' => 'owner'.uniqid().'@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('creates a company, attaches the owner, and fires CompanyCreated', function () {
    Event::fake([CompanyCreated::class]);
    $user = creator();

    $company = app(CreateCompanyAction::class)->execute($user, ['name' => 'Acme Ltd']);

    expect($company)->toBeInstanceOf(Company::class)
        ->and($company->name)->toBe('Acme Ltd')
        ->and($company->slug)->not->toBeEmpty()
        ->and($company->created_by_id)->toBe($user->id)
        ->and($user->isOwnerOf($company))->toBeTrue();

    Event::assertDispatched(CompanyCreated::class, fn ($e) => $e->company->is($company) && $e->owner->is($user));
});

it('forbids the platform super-admin from owning a company', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    $admin = User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'secret-pass',
        'is_admin' => true,
        'email_verified_at' => now(),
    ]);

    expect(fn () => app(CreateCompanyAction::class)->execute($admin, ['name' => 'Acme']))
        ->toThrow(HttpException::class);

    expect(Company::count())->toBe(0);
});

it('forbids a reserved-email account from owning a company even without the admin flag', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    // A reserved-email account that never received the is_admin flag (defence in
    // depth: the email guard catches it even though isAdmin() would not).
    $squatter = User::create([
        'name' => 'Squatter',
        'email' => 'Boss@x.test',
        'password' => 'secret-pass',
        'is_admin' => false,
        'email_verified_at' => now(),
    ]);

    expect(fn () => app(CreateCompanyAction::class)->execute($squatter, ['name' => 'Acme']))
        ->toThrow(HttpException::class);

    expect(Company::count())->toBe(0);
});

it('generates a unique slug when names collide', function () {
    $user = creator();
    $action = app(CreateCompanyAction::class);

    $a = $action->execute($user, ['name' => 'Same Name']);
    $b = $action->execute($user, ['name' => 'Same Name']);

    expect($a->slug)->not->toBe($b->slug);
});

it('does NOT create roles in phase 1.2 (RBAC deferred)', function () {
    // The hook fires, but nothing clones roles yet — assert no role tables are
    // touched by confirming the company has no roles relation wired here.
    $user = creator();
    $company = app(CreateCompanyAction::class)->execute($user, ['name' => 'No Roles']);

    expect(method_exists($company, 'roles'))->toBeFalse();
});

it('queues invitations on the final wizard step', function () {
    Queue::fake();
    $owner = creator();

    // Drive the whole flow over HTTP so company-creation and the invite step
    // share one session (step 1 creates + activates the company; SetActiveTenant
    // then keeps it active for step 3).
    $this->actingAs($owner)
        ->post('/companies/create/step1', ['name' => 'Acme'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post('/companies/create/step3', [
            'invitations' => [['email' => 'a@x.test'], ['email' => 'b@x.test']],
        ])
        ->assertRedirect();

    expect(CompanyInvitation::count())->toBe(2)
        ->and(Company::first()->invitations()->pending()->count())->toBe(2);
});

it('step 2 does not clobber the description captured in step 1', function () {
    // Step 1 collects the description; step 2 is logo-only. Submitting step 2
    // must NOT wipe the step-1 description with a blank string.
    $owner = creator();
    $company = app(CreateCompanyAction::class)->execute($owner, [
        'name' => 'Acme',
        'description' => 'We make widgets.',
    ]);

    $this->actingAs($owner)->post('/companies/create/step2', [])->assertRedirect();

    expect($company->fresh()->description)->toBe('We make widgets.');
});

it('rejects step 2/3 for a user who is not the active owner', function () {
    $owner = creator();
    $intruder = creator();
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    // The intruder has no active company → ownedActiveCompany() aborts 403.
    $this->actingAs($intruder)
        ->post('/companies/create/step3', ['invitations' => [['email' => 'x@x.test']]])
        ->assertForbidden();
});

it('resumes the wizard on the requested step once a company exists', function () {
    // The owner already has a company, so SetActiveTenant auto-selects it as the
    // active tenant for this request and ?step=3 is honoured. (We create the
    // company directly; the middleware does the activation, so this doesn't lean
    // on a session cookie carrying over between two separate HTTP calls.)
    $owner = creator();
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->get('/companies/create?step=3')
        ->assertOk()
        ->assertJsonPath('props.step', 3);
});

it('clamps the wizard to step 1 when the user has no company yet', function () {
    $user = creator();

    // A stale ?step=3 link cannot skip company creation.
    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->get('/companies/create?step=3')
        ->assertOk()
        ->assertJsonPath('props.step', 1);
});
