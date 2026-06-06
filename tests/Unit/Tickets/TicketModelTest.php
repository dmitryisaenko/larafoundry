<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tmUser(string $email = 'owner@x.test'): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function tmTicket(User $user, array $attrs = []): Ticket
{
    return Ticket::create(array_merge([
        'user_id' => $user->id,
        'title' => 'Help',
        'message' => 'Something is wrong here.',
        'priority' => 'standard',
        'status' => Ticket::STATUS_WAIT_MODERATOR,
    ], $attrs));
}

it('assigns a uuid on creation', function () {
    $ticket = tmTicket(tmUser());

    expect($ticket->uuid)->toBeString()->not->toBe('');
});

it('casts categories and labels to arrays', function () {
    $ticket = tmTicket(tmUser(), ['categories' => ['billing', 'bug'], 'labels' => ['quick']]);

    $fresh = Ticket::find($ticket->id);

    expect($fresh->categories)->toBe(['billing', 'bug'])
        ->and($fresh->labels)->toBe(['quick']);
});

it('hides resolved tickets older than the configured window but keeps recent ones and open ones', function () {
    config(['larafoundry-tickets.resolved_hidden_after_days' => 7]);
    $user = tmUser();

    $open = tmTicket($user, ['title' => 'open']);

    $recentResolved = tmTicket($user, ['title' => 'recent', 'status' => Ticket::STATUS_RESOLVED]);
    $recentResolved->forceFill(['updated_at' => now()->subDays(2)])->save();

    $oldResolved = tmTicket($user, ['title' => 'old', 'status' => Ticket::STATUS_RESOLVED]);
    $oldResolved->forceFill(['updated_at' => now()->subDays(30)])->save();

    $titles = Ticket::query()->excludeOldResolved()->pluck('title')->all();

    expect($titles)->toContain('open')
        ->toContain('recent')
        ->not->toContain('old');
});

it('flags a brand-new ticket as new and an answered one as not', function () {
    $user = tmUser();

    $new = tmTicket($user);
    expect($new->fresh()->isNew())->toBeTrue();

    // A reply arrives later and (via $touches) advances the ticket's updated_at.
    $this->travel(1)->minute();
    $new->messages()->create(['user_id' => $user->id, 'message' => 'a reply here']);

    expect($new->fresh()->isNew())->toBeFalse();
});

it('orders open tickets before resolved ones via the workflow scope', function () {
    $user = tmUser();

    $resolved = tmTicket($user, ['title' => 'resolved', 'status' => Ticket::STATUS_RESOLVED]);
    $resolved->forceFill(['updated_at' => now()])->save();
    $open = tmTicket($user, ['title' => 'open', 'status' => Ticket::STATUS_WAIT_MODERATOR]);

    $ordered = Ticket::query()->workflowOrder()->pluck('title')->all();

    expect(array_search('open', $ordered, true))
        ->toBeLessThan(array_search('resolved', $ordered, true));
});
