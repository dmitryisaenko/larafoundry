<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Notifications\Providers\NotificationsUserDataExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\ConsentExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\UiSettingsExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\UserSessionsExporter;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Models\TicketMessage;
use Dmitryisaenko\LaraFoundry\Tickets\Providers\TicketsUserDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- sessions ---------------------------------------------------------------

it('exports the session fingerprint but never the session id or pin state', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->sessions()->create([
        'session_id' => str_pad('s', 40, 'a'),
        'login_method' => 'native',
        'ip_address' => '1.1.1.1',
        'user_browser' => 'Chrome',
        'pin_locked' => true,
    ]);

    $rows = (new UserSessionsExporter)->exportFor($user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['ip_address', 'browser', 'login_method', 'last_activity'])
        ->and($rows[0])->not->toHaveKey('session_id')
        ->and($rows[0])->not->toHaveKey('pin_locked')
        ->and($rows[0]['browser'])->toBe('Chrome');
});

// --- ui settings ------------------------------------------------------------

it('exports the resolved ui preferences', function () {
    $user = User::create([
        'name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass',
        'ui_settings' => ['theme' => 'dark', 'not_allowed' => 'leak'],
    ]);

    $settings = (new UiSettingsExporter)->exportFor($user);

    expect($settings['theme'])->toBe('dark')
        ->and($settings)->toHaveKey('table_density')
        ->and($settings)->not->toHaveKey('not_allowed');
});

// --- consent ----------------------------------------------------------------

it('exports the stored consent state for the user', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Settings::set('cookie_consent', true, $user->getKey());
    Settings::set('terms_accepted_version', '3', $user->getKey());

    $consent = (new ConsentExporter)->exportFor($user);

    expect($consent['cookie_consent'])->toBeTrue()
        ->and($consent['terms_accepted_version'])->toBe('3')
        ->and($consent)->toHaveKey('terms_accepted_at');
});

// --- tickets ----------------------------------------------------------------

it('exports only the user\'s own tickets and labels message authorship', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $operator = User::create(['name' => 'Op', 'email' => 'op@x.test', 'password' => 'secret-pass']);
    $stranger = User::create(['name' => 'S', 'email' => 'stranger@x.test', 'password' => 'secret-pass']);

    $ticket = Ticket::create([
        'user_id' => $user->getKey(),
        'title' => 'My issue',
        'message' => 'Help me',
        'priority' => 'standard',
        'status' => Ticket::STATUS_WAIT_MODERATOR,
    ]);
    TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $user->getKey(), 'message' => 'mine']);
    TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $operator->getKey(), 'message' => 'theirs']);

    // A ticket the stranger owns must never appear in the user's export.
    Ticket::create([
        'user_id' => $stranger->getKey(),
        'title' => 'Not yours',
        'message' => 'secret',
        'priority' => 'standard',
        'status' => Ticket::STATUS_WAIT_MODERATOR,
    ]);

    $tickets = (new TicketsUserDataExporter)->exportFor($user);

    expect($tickets)->toHaveCount(1)
        ->and($tickets[0]['title'])->toBe('My issue')
        ->and($tickets[0]['reference'])->toBe($ticket->uuid)
        ->and($tickets[0]['messages'])->toHaveCount(2)
        ->and($tickets[0]['messages'][0]['from'])->toBe('you')
        ->and($tickets[0]['messages'][1]['from'])->toBe('support');

    // The operator's identity is never disclosed — only a side label.
    expect(json_encode($tickets))->not->toContain('op@x.test');
});

// --- notifications ----------------------------------------------------------

it('exports only the user\'s own notifications with read state', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $stranger = User::create(['name' => 'S', 'email' => 'stranger@x.test', 'password' => 'secret-pass']);

    $read = Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Read one'],
        'body_translations' => ['en' => 'Body'],
    ]);
    $unread = Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Unread one'],
        'body_translations' => ['en' => 'Body'],
    ]);
    $user->appNotifications()->attach($read->id, ['read_at' => now()]);
    $user->appNotifications()->attach($unread->id, ['read_at' => null]);

    // The stranger's notification must not bleed into the user's export.
    $others = Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Stranger only'],
        'body_translations' => ['en' => 'Body'],
    ]);
    $stranger->appNotifications()->attach($others->id);

    $rows = (new NotificationsUserDataExporter)->exportFor($user);
    $titles = array_column($rows, 'title');

    expect($rows)->toHaveCount(2)
        ->and($titles)->toContain('Read one')
        ->and($titles)->toContain('Unread one')
        ->and($titles)->not->toContain('Stranger only');

    $readRow = collect($rows)->firstWhere('title', 'Read one');
    $unreadRow = collect($rows)->firstWhere('title', 'Unread one');
    expect($readRow['read_at'])->not->toBeNull()
        ->and($unreadRow['read_at'])->toBeNull();
});
