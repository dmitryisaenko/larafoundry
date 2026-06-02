<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Dmitryisaenko\LaraFoundry\Auth\Support\UserAgentDeviceResolver;
use Illuminate\Http\Request;

function uaRequest(string $userAgent): Request
{
    return Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => $userAgent]);
}

$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0';
$safariMac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
$edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$androidPhone = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
$ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/604.1';
$bot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$googlebotMobile = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

it('classifies a desktop browser', function () use ($chrome) {
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest($chrome));
    expect($fp->type)->toBe('desktop');
});

it('classifies a mobile phone', function () use ($androidPhone) {
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest($androidPhone));
    expect($fp->type)->toBe('mobile');
});

it('classifies an iPad as a tablet', function () use ($ipad) {
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest($ipad));
    expect($fp->type)->toBe('tablet');
});

it('classifies a crawler as a robot', function () use ($bot) {
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest($bot));
    expect($fp->type)->toBe('robot');
});

it('classifies a Googlebot-mobile UA as a robot, not a mobile', function () use ($googlebotMobile) {
    // The mobile crawler UA embeds "Android … Mobile" tokens; the robot check
    // must run first or it gets misclassified as a phone.
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest($googlebotMobile));
    expect($fp->type)->toBe('robot');
});

it('detects the Chrome browser', function () use ($chrome) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($chrome))->browser)->toBe('Chrome');
});

it('detects the Firefox browser', function () use ($firefox) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($firefox))->browser)->toBe('Firefox');
});

it('detects the Safari browser', function () use ($safariMac) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($safariMac))->browser)->toBe('Safari');
});

it('detects Edge over Chrome (specificity order)', function () use ($edge) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($edge))->browser)->toBe('Edge');
});

it('detects the iOS operating system', function () use ($iphone) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($iphone))->os)->toBe('iOS');
});

it('detects the Android operating system', function () use ($androidPhone) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($androidPhone))->os)->toBe('Android');
});

it('detects the Windows operating system', function () use ($chrome) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($chrome))->os)->toBe('Windows 10/11');
});

it('detects the macOS operating system', function () use ($safariMac) {
    expect((new UserAgentDeviceResolver)->resolve(uaRequest($safariMac))->os)->toBe('macOS');
});

it('returns an all-null fingerprint for an empty user agent', function () {
    // Request::create() injects a default "Symfony" UA; force it empty.
    $fp = (new UserAgentDeviceResolver)->resolve(uaRequest(''));

    expect($fp)->toBeInstanceOf(DeviceFingerprint::class)
        ->and($fp->type)->toBeNull()
        ->and($fp->name)->toBeNull()
        ->and($fp->os)->toBeNull()
        ->and($fp->browser)->toBeNull();
});

it('maps the fingerprint to session attributes', function () use ($chrome) {
    $attrs = (new UserAgentDeviceResolver)->resolve(uaRequest($chrome))->toSessionAttributes();

    expect($attrs)->toEqual([
        'user_device_type' => 'desktop',
        'user_device_name' => 'Windows PC',
        'user_os' => 'Windows 10/11',
        'user_browser' => 'Chrome',
    ]);
});
