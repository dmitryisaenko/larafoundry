<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Onboarding\Support;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Onboarding\Contracts\OnboardingStepProviderInterface;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Builds the getting-started checklist for a tenant user (phase 5.4).
 *
 * The flat twin of the {@see DashboardBuilder}
 * (which is itself the twin of the MenuBuilder): it merges steps from every
 * supporting provider, de-dups by key, sorts by step order, resolves each CTA
 * route name to a url and tallies progress.
 *
 * The checklist is GENTLE and dismissible — never a blocking wizard. The builder
 * returns null for anyone it should not nag (a guest or the platform super-admin,
 * who is the operator, not a tenant user), and short-circuits for a user who has
 * dismissed it so the per-request cost stays at a single settings read.
 *
 * Registered as a singleton; results are memoised per user for the request, since
 * `build()` runs from the Inertia shared-prop closure on every page and a step's
 * completion check touches the database.
 */
class OnboardingBuilder
{
    /**
     * @var array<int, OnboardingStepProviderInterface>
     */
    protected array $providers = [];

    /**
     * Per-request memo of built checklists, keyed by user id.
     *
     * @var array<string, array<string, mixed>|null>
     */
    protected array $cache = [];

    /**
     * Register a provider. Providers are kept ordered by their priority (lower
     * first); the final step list is still sorted by step `order`.
     */
    public function addProvider(OnboardingStepProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        usort($this->providers, static fn (OnboardingStepProviderInterface $a, OnboardingStepProviderInterface $b) => $a->priority() <=> $b->priority());

        return $this;
    }

    /**
     * Build the checklist for a user: collect -> de-dup -> sort -> tally.
     *
     * Returns null when there is nobody to onboard — a guest, or the platform
     * super-admin (the operator manages the platform, they are not a tenant user
     * to nag). For a user who has dismissed the checklist, short-circuits to an
     * empty, dismissed payload WITHOUT computing any step completion (one settings
     * read, no per-step queries). Otherwise emits:
     *
     *   ['dismissed' => bool, 'steps' => [...], 'completed' => int,
     *    'total' => int, 'all_complete' => bool]
     *
     * @return array<string, mixed>|null
     */
    public function build(?Authenticatable $user = null): ?array
    {
        $user ??= auth()->user();

        if ($user === null) {
            return null;
        }

        // The super-admin is the platform operator, not a tenant user — the
        // checklist targets tenant users, so it never renders for them. Detected
        // through the same VisitorStatus seam the navigation uses to pick a shell,
        // so "who is the operator" is decided in one place.
        if (app(VisitorStatus::class)->isAdmin($user)) {
            return null;
        }

        $key = (string) ($user->getAuthIdentifier() ?? 'guest');

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $dismissed = (bool) app(SettingsRepository::class)
            ->get('onboarding.dismissed', $user->getAuthIdentifier());

        if ($dismissed) {
            // Short-circuit: a dismissed user costs one settings read, never a
            // per-step completion query. Nothing is ever forced on them.
            return $this->cache[$key] = [
                'dismissed' => true,
                'steps' => [],
                'completed' => 0,
                'total' => 0,
                'all_complete' => false,
            ];
        }

        $steps = $this->collectSteps($user);
        $steps = $this->dedupe($steps);
        $steps = $this->sort($steps);
        $steps = $this->resolveUrls($steps);

        $total = count($steps);
        $completed = count(array_filter($steps, static fn (array $step) => ($step['complete'] ?? false) === true));

        return $this->cache[$key] = [
            'dismissed' => false,
            'steps' => array_values($steps),
            'completed' => $completed,
            'total' => $total,
            'all_complete' => $total > 0 && $completed === $total,
        ];
    }

    /**
     * Forget memoised checklists — for tests, or after a context change.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Merge steps from every provider that supports the checklist.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectSteps(Authenticatable $user): array
    {
        $steps = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports()) {
                $steps = array_merge($steps, $provider->steps($user));
            }
        }

        return $steps;
    }

    /**
     * Keep the first step for each key — a later provider cannot silently
     * shadow an earlier (higher-priority) one's step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function dedupe(array $steps): array
    {
        $seen = [];
        $unique = [];

        foreach ($steps as $step) {
            $key = $step['key'] ?? null;

            if ($key === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $step;
        }

        return $unique;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function sort(array $steps): array
    {
        usort($steps, static fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $steps;
    }

    /**
     * Resolve each step's `cta_route` name to a `cta_url`, guarding on route
     * existence so a step whose host route is not registered still ships (the
     * Vue layer just renders no link for it).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function resolveUrls(array $steps): array
    {
        return array_map(static function (array $step) {
            $name = $step['cta_route'] ?? null;

            $step['cta_url'] = is_string($name) && $name !== '' && Route::has($name)
                ? route($name)
                : null;

            return $step;
        }, $steps);
    }
}
