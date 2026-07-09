<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns;

use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared helpers for the owner-employee lifecycle listeners (phase 2a): the
 * master-switch gate, resolving the owner, a display name, a locale, and the
 * standard in-app deep-link `data.actions` shape reused from the tickets seam.
 */
trait NotifiesLifecycle
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Whether the lifecycle notification wiring is enabled (master switch). When
     * off the listeners no-op, so a host can silence the whole set while the
     * events still fire and still feed the activity log.
     */
    protected function enabled(): bool
    {
        return (bool) config('larafoundry-notifications.lifecycle.enabled', true);
    }

    /**
     * The company's owner (the is_owner pivot), or null for an ownerless company.
     */
    protected function ownerOf(Company $company): ?Model
    {
        return $company->owners()->first();
    }

    /**
     * A human display name for a user model, falling back to the empty string so a
     * partially-populated fixture never renders "null".
     */
    protected function displayName(?object $user): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim((string) ($user->name ?? '').' '.(string) ($user->lastname ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? '');
    }

    /**
     * The recipient's stored locale for baking a static `data.actions` label (the
     * store does NOT re-localise the action label at read time), falling back to
     * the current app locale.
     */
    protected function localeFor(?object $user): string
    {
        if ($user !== null && method_exists($user, 'preferredLocale')) {
            return $user->preferredLocale() ?? app()->getLocale();
        }

        return app()->getLocale();
    }

    /**
     * The standard in-app deep-link action payload (tickets `data.actions` shape).
     *
     * @return array<string, mixed>
     */
    protected function action(string $labelKey, string $url, string $locale): array
    {
        return ['actions' => [[
            'label' => __($labelKey, [], $locale),
            'url' => $url,
        ]]];
    }

    /**
     * Resolve the users a notification targets from a mixed set, dropping nulls.
     *
     * @param  array<int, Authenticatable|Model|null>  $users
     * @return list<Model>
     */
    protected function recipients(array $users): array
    {
        return Collection::make($users)
            ->filter(fn ($user) => $user instanceof Model)
            ->values()
            ->all();
    }
}
