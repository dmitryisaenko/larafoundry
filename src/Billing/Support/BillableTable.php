<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * The DB table(s) the add-on's billable columns belong on.
 *
 * The billable is configurable — `larafoundry-billing.billable_model` (Stripe) and
 * `paddle_billable_model` (Paddle, a separate class because the two Cashier packages'
 * traits collide). In `teams` mode a host points them at its Company subclass, so the
 * table is `companies`; in `personal` mode the payer is the user, so it is `users`.
 *
 * The add-on's schema migrations used to hardcode `companies`, which silently produced
 * a `personal`-mode install with no Cashier identity columns anywhere — `Billable` on
 * the user could not find `stripe_id`, and nothing about billing worked. They now ask
 * this helper instead. With the shipped defaults the answer is still `companies`, so
 * existing installs migrate to exactly the same schema as before.
 *
 * Every lookup fails soft (null / an empty list): a migration must not fatal because a
 * host mis-typed a class name in config — it simply adds no column, and the guarded
 * `hasColumn` checks make a later, corrected run pick up where it left off.
 */
final class BillableTable
{
    /**
     * Config key of the Cashier-Stripe billable (the Cashier identity columns).
     */
    public const STRIPE = 'larafoundry-billing.billable_model';

    /**
     * Config key of the Cashier-Paddle billable (may differ from the Stripe one).
     */
    public const PADDLE = 'larafoundry-billing.paddle_billable_model';

    /**
     * The table behind one configured billable class, or null when the config value is
     * missing / not an Eloquent model.
     */
    public static function for(string $configKey): ?string
    {
        $class = config($configKey);

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        $table = $model->getTable();

        return $table !== '' ? $table : null;
    }

    /**
     * Every distinct billable table (Stripe + Paddle), for columns that belong to the
     * subject regardless of which gateway charges it — e.g. `billing_period`.
     *
     * Deduplicated: with the shipped defaults both keys resolve to `companies`, and a
     * host that declares two Company subclasses has them share one table too.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $tables = [];

        foreach ([self::STRIPE, self::PADDLE] as $key) {
            $table = self::for($key);

            if ($table !== null && ! in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * The core's own `companies` table.
     *
     * The core owns the subscription-state columns on it (its phase-3.1 migration), so
     * the add-on must never add or drop them there — only on a billable table the core
     * knows nothing about, which is what `personal` mode produces.
     */
    public static function core(): string
    {
        return (new Company)->getTable();
    }
}
