<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketCreated;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketReplied;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

function utUser(string $email, bool $admin = false): User
{
    $user = User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);

    if ($admin) {
        $user->forceFill(['is_admin' => true])->save();
    }

    return $user;
}

function utTicket(User $user, array $attrs = []): Ticket
{
    return Ticket::create(array_merge([
        'user_id' => $user->id,
        'title' => 'Help me',
        'message' => 'Something is broken here.',
        'priority' => 'standard',
        'status' => Ticket::STATUS_WAIT_MODERATOR,
    ], $attrs));
}

it('redirects a guest away from the inbox', function () {
    $this->get('/tickets')->assertRedirect();
});

it('lists only the user own tickets', function () {
    $me = utUser('me@x.test');
    utTicket($me, ['title' => 'mine-a']);
    utTicket($me, ['title' => 'mine-b']);

    $other = utUser('other@x.test');
    utTicket($other, ['title' => 'theirs']);

    $this->actingAs($me)
        ->get('/tickets', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Tickets/Index')
        ->assertJsonCount(2, 'props.tickets.data');
});

it('creates a ticket in the wait-moderator state and dispatches the event', function () {
    Event::fake([TicketCreated::class]);
    $me = utUser('me@x.test');

    $this->actingAs($me)
        ->post('/tickets', [
            'title' => 'Cannot log in',
            'message' => 'I keep getting an error on login.',
            'priority' => 'high',
            'categories' => ['billing'],
        ])
        ->assertRedirect();

    $ticket = Ticket::first();
    expect($ticket->status)->toBe(Ticket::STATUS_WAIT_MODERATOR)
        ->and($ticket->user_id)->toBe($me->id)
        ->and($ticket->categories)->toBe(['billing'])
        ->and($ticket->priority)->toBe('high');

    Event::assertDispatched(TicketCreated::class);
});

it('rejects a ticket without a title or with too short a message', function () {
    $me = utUser('me@x.test');

    $this->actingAs($me)
        ->post('/tickets', ['title' => '', 'message' => 'short'])
        ->assertSessionHasErrors(['title', 'message']);

    expect(Ticket::count())->toBe(0);
});

it('rejects a category outside the configured list', function () {
    $me = utUser('me@x.test');

    $this->actingAs($me)
        ->post('/tickets', [
            'title' => 'Hi',
            'message' => 'A valid length message body.',
            'categories' => ['not-a-real-category'],
        ])
        ->assertSessionHasErrors('categories.0');

    expect(Ticket::count())->toBe(0);
});

it('lets the owner view their own ticket', function () {
    $me = utUser('me@x.test');
    $ticket = utTicket($me);

    $this->actingAs($me)
        ->get("/tickets/{$ticket->uuid}", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Tickets/Show')
        ->assertJsonPath('props.ticket.uuid', $ticket->uuid);
});

it('forbids viewing another user ticket (IDOR)', function () {
    $me = utUser('me@x.test');
    $other = utUser('other@x.test');
    $theirs = utTicket($other);

    $this->actingAs($me)
        ->get("/tickets/{$theirs->uuid}")
        ->assertForbidden();
});

it('reopens a resolved ticket when the owner replies and dispatches the event', function () {
    Event::fake([TicketReplied::class]);
    $me = utUser('me@x.test');
    $ticket = utTicket($me, ['status' => Ticket::STATUS_RESOLVED]);

    $this->actingAs($me)
        ->post("/tickets/{$ticket->uuid}/messages", ['message' => 'Still broken!'])
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_WAIT_MODERATOR)
        ->and($ticket->messages()->count())->toBe(1);

    Event::assertDispatched(TicketReplied::class);
});

it('forbids replying to another user ticket', function () {
    $me = utUser('me@x.test');
    $other = utUser('other@x.test');
    $theirs = utTicket($other);

    $this->actingAs($me)
        ->post("/tickets/{$theirs->uuid}/messages", ['message' => 'sneaky reply'])
        ->assertForbidden();

    expect($theirs->messages()->count())->toBe(0);
});

it('lets a blocked user still reach their tickets (support is their only channel)', function () {
    $me = utUser('blocked@x.test');
    $me->forceFill(['user_blocked_at' => now()])->save();
    utTicket($me);

    $this->actingAs($me)
        ->get('/tickets', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Tickets/Index');
});

it('throttles a flood of new tickets', function () {
    // The default limit (config 'rate_limit.create' = '5,1') is baked onto the
    // route at registration; the 6th create within the window is rejected.
    $me = utUser('me@x.test');

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($me)
            ->post('/tickets', ['title' => "T{$i}", 'message' => 'A valid message body.'])
            ->assertRedirect();
    }

    $this->actingAs($me)
        ->post('/tickets', ['title' => 'overflow', 'message' => 'A valid message body.'])
        ->assertStatus(429);
});
