<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketReplied;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    // OTP step-up gate is covered elsewhere; run console tests with it off.
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function atAdmin(string $email = 'boss@x.test'): User
{
    return User::create([
        'name' => 'Boss',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function atMember(string $email = 'joe@x.test'): User
{
    return User::create([
        'name' => 'Joe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function atTicket(User $user, array $attrs = []): Ticket
{
    return Ticket::create(array_merge([
        'user_id' => $user->id,
        'title' => 'Help me',
        'message' => 'Something is broken here.',
        'priority' => 'standard',
        'status' => Ticket::STATUS_WAIT_MODERATOR,
    ], $attrs));
}

it('forbids a non-admin from the operator queue', function () {
    $this->actingAs(atMember())->get('/admin/tickets')->assertForbidden();
});

it('lets a super-admin list every open ticket', function () {
    $admin = atAdmin();
    atTicket(atMember('a@x.test'), ['title' => 'one']);
    atTicket(atMember('b@x.test'), ['title' => 'two']);
    // A resolved ticket is hidden from the default open queue.
    atTicket(atMember('c@x.test'), ['title' => 'done', 'status' => Ticket::STATUS_RESOLVED]);

    $this->actingAs($admin)
        ->get('/admin/tickets', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Tickets/Index')
        ->assertJsonCount(2, 'props.tickets.data')
        ->assertJsonPath('props.stats.total', 3)
        ->assertJsonPath('props.stats.open', 2);
});

it('includes resolved tickets when show_tickets=all', function () {
    $admin = atAdmin();
    atTicket(atMember('a@x.test'));
    atTicket(atMember('c@x.test'), ['status' => Ticket::STATUS_RESOLVED]);

    $this->actingAs($admin)
        ->get('/admin/tickets?show_tickets=all', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(2, 'props.tickets.data');
});

it('filters the queue by priority', function () {
    $admin = atAdmin();
    atTicket(atMember('a@x.test'), ['priority' => 'high']);
    atTicket(atMember('b@x.test'), ['priority' => 'standard']);

    $this->actingAs($admin)
        ->get('/admin/tickets?priority=high', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.tickets.data');
});

it('opens a ticket for a user (wait-customer) and notifies them', function () {
    $admin = atAdmin();
    $member = atMember();

    $this->actingAs($admin)
        ->post('/admin/tickets', [
            'user_id' => $member->id,
            'title' => 'Proactive outreach',
            'message' => 'We noticed an issue and opened this for you.',
            'priority' => 'high',
            'labels' => ['quick'],
        ])
        ->assertRedirect();

    $ticket = Ticket::first();
    expect($ticket->status)->toBe(Ticket::STATUS_WAIT_CUSTOMER)
        ->and($ticket->user_id)->toBe($member->id)
        ->and($ticket->labels)->toBe(['quick']);

    expect($member->appNotifications()->count())->toBe(1)
        ->and($member->appNotifications()->first()->title_key)
        ->toBe('larafoundry::tickets.notify.opened.title');
});

it('rejects opening a ticket for a non-existent user', function () {
    $admin = atAdmin();

    $this->actingAs($admin)
        ->post('/admin/tickets', [
            'user_id' => 99999,
            'title' => 'x',
            'message' => 'a valid message',
        ])
        ->assertSessionHasErrors('user_id');
});

it('lets the operator reply, moving the ticket to wait-customer and notifying the author', function () {
    Event::fake([TicketReplied::class]);
    $admin = atAdmin();
    $member = atMember();
    $ticket = atTicket($member);

    $this->actingAs($admin)
        ->post("/admin/tickets/{$ticket->id}/reply", ['message' => 'Here is the fix.'])
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_WAIT_CUSTOMER)
        ->and($ticket->messages()->count())->toBe(1)
        ->and($member->appNotifications()->count())->toBe(1)
        ->and($member->appNotifications()->first()->title_key)
        ->toBe('larafoundry::tickets.notify.reply.title');

    Event::assertDispatched(TicketReplied::class, fn ($e) => $e->byOperator === true);
});

it('pushes a notification whose action label is translated, not a raw key', function () {
    $admin = atAdmin();
    $member = atMember();
    $ticket = atTicket($member);

    $this->actingAs($admin)
        ->post("/admin/tickets/{$ticket->id}/reply", ['message' => 'Here is the fix.'])
        ->assertRedirect();

    $action = $member->appNotifications()->first()->data['actions'][0];
    expect($action['label'])->toBe('View ticket')
        ->and($action['url'])->toBe("/tickets/{$ticket->uuid}");
});

it('ignores an unknown status filter and still shows the open queue', function () {
    $admin = atAdmin();
    atTicket(atMember('a@x.test'), ['title' => 'open one']);
    atTicket(atMember('c@x.test'), ['title' => 'done', 'status' => Ticket::STATUS_RESOLVED]);

    // A typo'd status must not empty the queue nor leak resolved tickets.
    $this->actingAs($admin)
        ->get('/admin/tickets?status=bogus', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.tickets.data');
});

it('closes a ticket and writes an audit entry', function () {
    $admin = atAdmin();
    $ticket = atTicket(atMember());

    $this->actingAs($admin)
        ->patch("/admin/tickets/{$ticket->id}/close")
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED)
        ->and(ActivityModel::where('description', 'admin.ticket.closed')->exists())->toBeTrue();
});

it('audits a status change on update', function () {
    $admin = atAdmin();
    $ticket = atTicket(atMember());

    $this->actingAs($admin)
        ->put("/admin/tickets/{$ticket->id}", [
            'status' => Ticket::STATUS_WAIT_CUSTOMER,
        ])
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_WAIT_CUSTOMER)
        ->and(ActivityModel::where('description', 'admin.ticket.status_changed')->exists())->toBeTrue();
});

it('toggles a category on and off', function () {
    $admin = atAdmin();
    $ticket = atTicket(atMember());

    $this->actingAs($admin)
        ->post("/admin/tickets/{$ticket->id}/category", ['slug' => 'billing'])
        ->assertRedirect();
    expect($ticket->fresh()->categories)->toBe(['billing']);

    $this->actingAs($admin)
        ->post("/admin/tickets/{$ticket->id}/category", ['slug' => 'billing'])
        ->assertRedirect();
    expect($ticket->fresh()->categories)->toBe([]);
});

it('rejects toggling a category outside the configured list', function () {
    $admin = atAdmin();
    $ticket = atTicket(atMember());

    $this->actingAs($admin)
        ->post("/admin/tickets/{$ticket->id}/category", ['slug' => 'not-real'])
        ->assertSessionHasErrors('slug');
});

it('updates priority and audits it', function () {
    $admin = atAdmin();
    $ticket = atTicket(atMember(), ['priority' => 'standard']);

    $this->actingAs($admin)
        ->patch("/admin/tickets/{$ticket->id}/priority", ['priority' => 'high'])
        ->assertRedirect();

    expect($ticket->fresh()->priority)->toBe('high')
        ->and(ActivityModel::where('description', 'admin.ticket.priority_changed')->exists())->toBeTrue();
});
