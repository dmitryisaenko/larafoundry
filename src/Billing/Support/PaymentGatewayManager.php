<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Closure;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the active payment-gateway driver (phase 3.1) — the Mail/Queue
 * Manager pattern, mirrored on the core's own TenantResolver seam.
 *
 * The free core registers exactly one driver, `null` ({@see NullPaymentGateway}),
 * and makes it the default, so out of the box billing is present-but-inert. The
 * paid `larafoundry-billing` add-on calls {@see extend()} to register real
 * drivers ('stripe', 'paddle', …) and points `config(...gateway.default)` at one;
 * a host does the same for a local PSP. Call sites resolve a gateway through
 * {@see driver()} and never know which one they got — swapping providers is one
 * config value.
 *
 * Resolved drivers are memoised per name so repeated resolution in a request
 * doesn't rebuild them.
 */
class PaymentGatewayManager
{
    /**
     * Driver factories keyed by name. Each receives the container and returns a
     * PaymentGatewayInterface.
     *
     * @var array<string, Closure(Container): PaymentGatewayInterface>
     */
    protected array $drivers = [];

    /**
     * Resolved driver instances, memoised by name.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $resolved = [];

    public function __construct(protected Container $container)
    {
        // The null driver is always available, so the manager can answer even
        // before the add-on registers anything. The add-on overrides 'null'
        // never; it adds 'stripe'/'paddle' alongside.
        $this->extend('null', fn (): PaymentGatewayInterface => new NullPaymentGateway);
    }

    /**
     * Register (or replace) a named driver factory. The add-on/host calls this
     * from its own service provider's register().
     *
     * @param  Closure(Container): PaymentGatewayInterface  $factory
     */
    public function extend(string $name, Closure $factory): static
    {
        $this->drivers[$name] = $factory;

        // A re-registered name must rebuild on next resolve, not serve a stale
        // memo (matters when a host overrides a driver after first use).
        unset($this->resolved[$name]);

        return $this;
    }

    /**
     * The resolved driver for the given name, or the configured default when
     * null. Memoised. Throws when the name was never registered — a clear signal
     * the add-on/host forgot to bind it, rather than a silent fallback that would
     * mask a misconfiguration.
     */
    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name ??= $this->defaultDriver();

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException(
                "Payment gateway driver [{$name}] is not registered. The free core "
                ."only ships the 'null' driver; register others via the "
                .'larafoundry-billing add-on or PaymentGatewayManager::extend().'
            );
        }

        return $this->resolved[$name] = ($this->drivers[$name])($this->container);
    }

    /**
     * The default driver name from config, falling back to 'null' so a missing
     * config key never yields a hard error here (the fail-fast lives in driver()).
     *
     * Treats an empty/whitespace value as "unset" too: `??` only catches null, but
     * `LARAFOUNDRY_BILLING_GATEWAY=` in a .env yields an empty string, which would
     * otherwise sail past `??` and make every driver() call throw on the missing
     * '' driver.
     */
    public function defaultDriver(): string
    {
        $default = config('larafoundry.billing.gateway.default');

        return is_string($default) && trim($default) !== '' ? $default : 'null';
    }

    /**
     * Whether the resolved default gateway can actually take money. The single
     * question most call sites need ("is billing live?"), without poking at
     * driver internals.
     */
    public function isConfigured(): bool
    {
        return $this->driver()->isConfigured();
    }

    /**
     * Names of every registered driver (for diagnostics / admin display).
     *
     * @return list<string>
     */
    public function availableDrivers(): array
    {
        return array_keys($this->drivers);
    }
}
