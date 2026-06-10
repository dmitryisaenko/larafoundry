<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Actions\CloneCompanyRolesAction;
use Dmitryisaenko\LaraFoundry\Authorization\Jobs\CloneCompanyRolesJob;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The synchronous, idempotent, locked role-clone ensure (Part B). Cloning stays
 * asynchronous on creation, but the wizard ensures roles exist inline before the
 * role-at-invite dropdown needs them, so correctness no longer depends on when the
 * database+cron queue ticks. The async job now delegates to this same action, so
 * both paths share one implementation and never duplicate. Uses rbac* helpers.
 */

it('clones the templates synchronously when the company has none yet', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // Suppress the async clone job: this is the "fast clicker" — roles do not exist
    // yet when the ensure runs.
    Queue::fake();
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    expect(Role::where('company_id', $company->id)->count())->toBe(0);

    app(CloneCompanyRolesAction::class)->execute($company);

    $roles = Role::where('company_id', $company->id)->get();
    expect($roles)->toHaveCount(1)
        ->and($roles->first()->slug)->toBe('member')
        ->and($roles->first()->is_template)->toBeFalse()
        ->and($roles->first()->is_global)->toBeFalse()
        ->and($roles->first()->permissions->pluck('slug'))->toContain('company.settings.view');
});

it('is a no-op when roles already exist, and the ensure + job never duplicate', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // No queue fake: the async job clones inline on creation.
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    expect(Role::where('company_id', $company->id)->count())->toBe(1);

    // Both paths run again later: the synchronous ensure AND a re-dispatched job.
    app(CloneCompanyRolesAction::class)->execute($company);
    (new CloneCompanyRolesJob($company))->handle();

    expect(Role::where('company_id', $company->id)->count())->toBe(1);
});

it('renders wizard step 3 with the cloned roles for a fast clicker', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // The queued clone has not run yet.
    Queue::fake();
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    expect(Role::where('company_id', $company->id)->count())->toBe(0);

    // Rendering step 3 ensures the roles inline and exposes them to the dropdown.
    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->get('/companies/create?step=3')
        ->assertOk()
        ->assertJsonPath('props.step', 3)
        ->assertJsonCount(1, 'props.roles');

    expect(Role::where('company_id', $company->id)->count())->toBe(1);
});
