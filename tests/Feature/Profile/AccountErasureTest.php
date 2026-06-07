<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Notifications\Providers\NotificationsUserDataPurger;
use Dmitryisaenko\LaraFoundry\Profile\Providers\CoreUserProfilePurger;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Models\TicketMessage;
use Dmitryisaenko\LaraFoundry\Tickets\Providers\TicketsUserDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Keep the audit writes synchronous and offline (no geo HTTP).
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

// --- Core purger ---------------------------------------------------------

it('anonymises the identity, wipes every secret and clears owned core data', function () {
    $user = User::create([
        'name' => 'Ada', 'lastname' => 'Lovelace', 'middlename' => 'M',
        'email' => 'ada@x.test', 'phone' => '+100', 'country' => 'GB',
        'password' => 'secret-pass',
        'two_factor_secret' => 'enc-2fa',
        'two_factor_recovery_codes' => 'enc-codes',
        'provider_name' => 'google', 'provider_id' => 'gid', 'provider_token' => 'tok',
    ]);
    $user->sessions()->create(['session_id' => str_pad('s', 40, 'a'), 'login_method' => 'native']);
    $user->createToken('mobile');
    Settings::set('cookie_consent', true, $user->getKey());

    app(CoreUserProfilePurger::class)->purgeFor($user);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('deleted-user-'.$user->getKey())
        ->and($fresh->email)->toBe('deleted-'.$user->getKey().'@deleted.invalid')
        ->and($fresh->lastname)->toBeNull()
        ->and($fresh->middlename)->toBeNull()
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->country)->toBeNull()
        ->and($fresh->password)->toBeNull()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->provider_name)->toBeNull()
        ->and($fresh->provider_id)->toBeNull()
        ->and($fresh->provider_token)->toBeNull()
        ->and($user->sessions()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0)
        ->and(Settings::has('cookie_consent', $user->getKey()))->toBeFalse();
});

it('deletes a stored avatar file but leaves an external avatar URL alone', function () {
    Storage::fake('public');

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Storage::disk('public')->put('avatars/me.jpg', 'bytes');
    $user->forceFill(['avatar' => 'avatars/me.jpg'])->save();

    app(CoreUserProfilePurger::class)->purgeFor($user);

    expect($user->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing('avatars/me.jpg');

    // An OAuth provider URL is not a disk path — purging must not throw on it.
    $oauth = User::create(['name' => 'B', 'email' => 'b@x.test']);
    $oauth->forceFill(['avatar' => 'https://cdn.example.test/x.jpg', 'provider_name' => 'google'])->save();

    app(CoreUserProfilePurger::class)->purgeFor($oauth);

    expect($oauth->fresh()->avatar)->toBeNull();
});

it('is idempotent — re-running produces the same anonymised row', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass']);

    app(CoreUserProfilePurger::class)->purgeFor($user);
    $once = $user->fresh()->only(['name', 'email']);

    app(CoreUserProfilePurger::class)->purgeFor($user->fresh());

    expect($user->fresh()->only(['name', 'email']))->toBe($once);
});

// --- Module purgers ------------------------------------------------------

it('deletes only the user\'s own tickets and their messages', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $other = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass']);

    $mine = $user->tickets()->create(['title' => 'Help', 'message' => 'Body']);
    $mine->messages()->create(['user_id' => $user->getKey(), 'message' => 'hi']);
    // An operator reply lives in MY thread and goes with it.
    $mine->messages()->create(['user_id' => $other->getKey(), 'message' => 'support reply']);
    $theirs = $other->tickets()->create(['title' => 'Theirs', 'message' => 'Body']);

    app(TicketsUserDataPurger::class)->purgeFor($user);

    expect(Ticket::whereKey($mine->getKey())->exists())->toBeFalse()
        ->and(Ticket::whereKey($theirs->getKey())->exists())->toBeTrue()
        ->and(TicketMessage::where('ticket_id', $mine->getKey())->count())->toBe(0);
});

it('detaches only the user\'s own notification inbox', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $other = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass']);

    $n = Notification::create([
        'code' => 'test.broadcast', 'notification_type' => 'admin', 'status' => 'sent',
        'title_translations' => ['en' => 'Hi'], 'body_translations' => ['en' => 'Body'],
    ]);
    $user->appNotifications()->attach($n);
    $other->appNotifications()->attach($n);

    app(NotificationsUserDataPurger::class)->purgeFor($user);

    expect(DB::table('larafoundry_notification_user')->where('user_id', $user->getKey())->count())->toBe(0)
        ->and(DB::table('larafoundry_notification_user')->where('user_id', $other->getKey())->count())->toBe(1);
});

// --- Cron command --------------------------------------------------------

it('purges accounts past the grace period and stamps them, leaving the rest', function () {
    config(['larafoundry-legal.erasure.grace_days' => 30]);

    $old = User::create(['name' => 'Old', 'email' => 'old@x.test', 'password' => 'secret-pass']);
    $old->forceFill(['user_deleted_at' => now()->subDays(40)])->save();

    $recent = User::create(['name' => 'Recent', 'email' => 'recent@x.test', 'password' => 'secret-pass']);
    $recent->forceFill(['user_deleted_at' => now()->subDays(5)])->save();

    $active = User::create(['name' => 'Active', 'email' => 'active@x.test', 'password' => 'secret-pass']);

    $this->artisan('larafoundry:purge-deleted-accounts')->assertSuccessful();

    expect($old->fresh()->user_purged_at)->not->toBeNull()
        ->and($old->fresh()->email)->toBe('deleted-'.$old->getKey().'@deleted.invalid')
        ->and($recent->fresh()->user_purged_at)->toBeNull()
        ->and($recent->fresh()->email)->toBe('recent@x.test')
        ->and($active->fresh()->user_purged_at)->toBeNull()
        ->and($active->fresh()->email)->toBe('active@x.test');

    expect(ActivityModel::query()
        ->where('description', 'account.purged')
        ->where('subject_id', $old->getKey())
        ->exists())->toBeTrue();
});

it('does not re-purge an account already stamped purged', function () {
    config(['larafoundry-legal.erasure.grace_days' => 30]);

    $user = User::create(['name' => 'Done', 'email' => 'done@x.test', 'password' => 'secret-pass']);
    $user->forceFill([
        'user_deleted_at' => now()->subDays(40),
        'user_purged_at' => now()->subDay(),
    ])->save();

    $this->artisan('larafoundry:purge-deleted-accounts')
        ->expectsOutputToContain('Purged 0 deleted account(s).')
        ->assertSuccessful();

    // Untouched: the email was never anonymised by this run.
    expect($user->fresh()->email)->toBe('done@x.test');
});

it('runs every registered purger end-to-end through the command', function () {
    config(['larafoundry-legal.erasure.grace_days' => 30]);

    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['user_deleted_at' => now()->subDays(40)])->save();
    $ticket = $user->tickets()->create(['title' => 'T', 'message' => 'M']);

    $this->artisan('larafoundry:purge-deleted-accounts')->assertSuccessful();

    expect($user->fresh()->name)->toBe('deleted-user-'.$user->getKey())
        ->and(Ticket::whereKey($ticket->getKey())->exists())->toBeFalse();
});
