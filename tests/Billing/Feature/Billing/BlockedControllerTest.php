<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The blocked page: one route, several modes. It renders the validated `type`, nulls an
 * unknown one (never reflected raw), and recomputes the live status server-side.
 */
function blockedActor(string $slug, bool $ownerBlocked = false): PaymentUser
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'user_blocked_at' => $ownerBlocked ? now() : null,
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'ZZ',
        'created_by_id' => $owner->getKey(),
    ])->save();

    $owner->activeCompany = $company->refresh();

    return $owner;
}

it('renders the blocked page with a validated type and live status', function () {
    $owner = blockedActor('blk-ownerban', ownerBlocked: true);

    $this->actingAs($owner)
        ->get('/billing/blocked?type=owner_banned', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Blocked')
        ->assertJsonPath('props.blockType', 'owner_banned')
        ->assertJsonPath('props.companyName', 'Acme')
        ->assertJsonPath('props.blockStatus.company_block_reason', 'owner_banned');
});

it('nulls an unknown block type instead of reflecting it', function () {
    $owner = blockedActor('blk-evil');

    $this->actingAs($owner)
        ->get('/billing/blocked?type=evil', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Blocked')
        ->assertJsonPath('props.blockType', null);
});
