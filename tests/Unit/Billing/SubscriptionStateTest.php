<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Illuminate\Database\Eloquent\Model;

/*
 * SubscriptionState is the single home of the access decision. These pin the
 * normalisation it does on raw column values WITHOUT a database: it accepts cast
 * dates and parseable strings, and degrades any garbage / empty value to
 * "no access" (fail-closed) rather than throwing.
 */

/**
 * A bare model carrying arbitrary billing attribute values (no casts), so we can
 * feed SubscriptionState exactly what a column might hold.
 *
 * No custom constructor: Eloquent calls `new static()` with no args internally,
 * so attributes are filled via forceFill after construction instead.
 */
function billable(array $attributes): Model
{
    $model = new class extends Model
    {
        protected $guarded = [];
    };

    return $model->forceFill($attributes);
}

it('reads a live trial from a cast date', function () {
    $state = SubscriptionState::for(billable(['trial_ends_at' => now()->addDay()]));

    expect($state->isOnTrial())->toBeTrue()
        ->and($state->hasAccess())->toBeTrue();
});

it('reads a live subscription from a parseable string', function () {
    $state = SubscriptionState::for(billable([
        'subscription_ends_at' => now()->addMonth()->toDateTimeString(),
    ]));

    expect($state->hasActiveSubscription())->toBeTrue()
        ->and($state->hasAccess())->toBeTrue();
});

it('treats null and empty values as no access', function () {
    $state = SubscriptionState::for(billable([
        'trial_ends_at' => null,
        'subscription_ends_at' => '',
    ]));

    expect($state->isOnTrial())->toBeFalse()
        ->and($state->hasActiveSubscription())->toBeFalse()
        ->and($state->hasAccess())->toBeFalse();
});

it('degrades an unparseable value to no access instead of throwing', function () {
    $state = SubscriptionState::for(billable([
        'trial_ends_at' => 'not-a-date',
        'subscription_ends_at' => 'garbage',
    ]));

    expect($state->hasAccess())->toBeFalse();
});

it('treats a past date as expired', function () {
    $state = SubscriptionState::for(billable([
        'trial_ends_at' => now()->subDay(),
        'subscription_ends_at' => now()->subMonth(),
    ]));

    expect($state->hasAccess())->toBeFalse();
});
