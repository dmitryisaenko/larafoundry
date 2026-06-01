<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\RestrictAuthByIp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

function passThrough(): Closure
{
    return fn (Request $request): Response => new Response('ok');
}

function requestFromIp(string $ip): Request
{
    return Request::create('/admin', 'GET', server: ['REMOTE_ADDR' => $ip]);
}

it('passes through outside production regardless of IP', function () {
    app()->detectEnvironment(fn () => 'local');
    config()->set('larafoundry.security.admin_ips', '10.0.0.1');

    $response = (new RestrictAuthByIp)->handle(requestFromIp('8.8.8.8'), passThrough());

    expect($response->getContent())->toBe('ok');
});

it('passes through in production when no allow-list is configured', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('larafoundry.security.admin_ips', '');

    $response = (new RestrictAuthByIp)->handle(requestFromIp('8.8.8.8'), passThrough());

    expect($response->getContent())->toBe('ok');
});

it('allows a whitelisted IP in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('larafoundry.security.admin_ips', '10.0.0.1, 10.0.0.2');

    $response = (new RestrictAuthByIp)->handle(requestFromIp('10.0.0.2'), passThrough());

    expect($response->getContent())->toBe('ok');
});

it('aborts 403 for a non-whitelisted IP in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('larafoundry.security.admin_ips', '10.0.0.1');

    (new RestrictAuthByIp)->handle(requestFromIp('8.8.8.8'), passThrough());
})->throws(HttpException::class);

it('accepts an array allow-list', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('larafoundry.security.admin_ips', ['10.0.0.1', '10.0.0.2']);

    $response = (new RestrictAuthByIp)->handle(requestFromIp('10.0.0.1'), passThrough());

    expect($response->getContent())->toBe('ok');
});
