<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\LaraFoundryServiceProvider;
use Illuminate\Support\Facades\Event;

/*
 * Auto-registration of community OAuth drivers (socialiteproviders/* packages).
 *
 * The core hangs a SocialiteWasCalled listener for any slug mapped in
 * `community_drivers` that is also enabled in `providers` AND whose handler class
 * is installed — with no hard dependency on socialiteproviders/* (everything is a
 * class_exists guard). These tests cover that matrix WITHOUT requiring the real
 * packages: we declare a stand-in for the manager event under its real FQCN (only
 * if the genuine package is absent) and local stub handler classes.
 */

// The event the core listens against. Declared under its real FQCN only when the
// genuine socialiteproviders/manager package is not installed, so this test is
// self-contained yet stays correct if the package is ever pulled in for real.
if (! class_exists('SocialiteProviders\\Manager\\SocialiteWasCalled')) {
    eval('namespace SocialiteProviders\\Manager; class SocialiteWasCalled {}');
}

/** A stub handler that records each time its handle() is invoked. */
class FakeCommunityDriverHandler
{
    public static int $handled = 0;

    public function handle(object $event): void
    {
        self::$handled++;
    }
}

const FAKE_HANDLER = '\\'.FakeCommunityDriverHandler::class;
const MANAGER_EVENT = '\\SocialiteProviders\\Manager\\SocialiteWasCalled';

beforeEach(function () {
    FakeCommunityDriverHandler::$handled = 0;
    // Make isolation independent of the shipped defaults: clear both the active
    // provider list and the map, then forget any listener registered for the
    // event we assert against, so every case starts from a known-empty state.
    config()->set('larafoundry.auth.oauth.providers', []);
    config()->set('larafoundry.auth.oauth.community_drivers', []);
    Event::forget(ltrim(MANAGER_EVENT, '\\'));
});

/**
 * Run the core's community-driver wiring against the CURRENT config, in isolation
 * from the provider's one-time boot — so each case sets its own config and gets a
 * deterministic result. Invokes the protected helper via a bound closure.
 */
function bootCommunityDrivers(): void
{
    $provider = new LaraFoundryServiceProvider(app());

    Closure::bind(function () {
        $this->bootOAuthCommunityDrivers();
    }, $provider, LaraFoundryServiceProvider::class)();
}

it('registers the listener for an enabled slug whose handler class exists', function () {
    config()->set('larafoundry.auth.oauth.providers', ['google', 'apple']);
    config()->set('larafoundry.auth.oauth.community_drivers', ['apple' => FAKE_HANDLER]);

    bootCommunityDrivers();

    expect(Event::hasListeners(ltrim(MANAGER_EVENT, '\\')))->toBeTrue();

    // Dispatching the event must actually reach the mapped handler's handle().
    Event::dispatch(app(ltrim(MANAGER_EVENT, '\\')));
    expect(FakeCommunityDriverHandler::$handled)->toBe(1);
});

it('does NOT register, and does not throw, when the handler class is absent', function () {
    config()->set('larafoundry.auth.oauth.providers', ['apple']);
    config()->set('larafoundry.auth.oauth.community_drivers', [
        'apple' => '\\SocialiteProviders\\DoesNotExist\\NopeExtendSocialite',
    ]);

    bootCommunityDrivers(); // must not throw

    expect(Event::hasListeners(ltrim(MANAGER_EVENT, '\\')))->toBeFalse();
});

it('does NOT register a slug that is mapped but not enabled in providers', function () {
    config()->set('larafoundry.auth.oauth.providers', ['google']); // apple NOT enabled
    config()->set('larafoundry.auth.oauth.community_drivers', ['apple' => FAKE_HANDLER]);

    bootCommunityDrivers();

    expect(Event::hasListeners(ltrim(MANAGER_EVENT, '\\')))->toBeFalse();
});

it('only wires the enabled subset when several slugs are mapped', function () {
    config()->set('larafoundry.auth.oauth.providers', ['google', 'apple']); // microsoft NOT enabled
    config()->set('larafoundry.auth.oauth.community_drivers', [
        'apple' => FAKE_HANDLER,
        'microsoft' => FAKE_HANDLER,
    ]);

    bootCommunityDrivers();

    // apple is enabled → one listener; microsoft mapped but not enabled → skipped.
    Event::dispatch(app(ltrim(MANAGER_EVENT, '\\')));
    expect(FakeCommunityDriverHandler::$handled)->toBe(1);
});

it('does nothing for native providers (no community_drivers entry)', function () {
    config()->set('larafoundry.auth.oauth.providers', ['google', 'facebook', 'twitter']);
    config()->set('larafoundry.auth.oauth.community_drivers', []);

    bootCommunityDrivers();

    expect(Event::hasListeners(ltrim(MANAGER_EVENT, '\\')))->toBeFalse();
});

it('ignores a non-string handler value without throwing', function () {
    config()->set('larafoundry.auth.oauth.providers', ['apple']);
    config()->set('larafoundry.auth.oauth.community_drivers', ['apple' => ['not', 'a', 'string']]);

    bootCommunityDrivers(); // must not throw

    expect(Event::hasListeners(ltrim(MANAGER_EVENT, '\\')))->toBeFalse();
});

it('ships apple and microsoft as the packaged default community-driver map', function () {
    // Read the packaged default straight from the config file so a stray edit to
    // the default map (e.g. sneaking in a colliding twitter/linkedin slug) is
    // caught here, and the verified FQCNs stay pinned.
    $config = require __DIR__.'/../../../config/larafoundry.php';

    expect($config['auth']['oauth']['community_drivers'])->toBe([
        'apple' => '\\SocialiteProviders\\Apple\\AppleExtendSocialite',
        'microsoft' => '\\SocialiteProviders\\Microsoft\\MicrosoftExtendSocialite',
    ]);
});
