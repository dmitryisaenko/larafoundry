<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Onboarding;

use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;
use Dmitryisaenko\LaraFoundry\Onboarding\Support\OnboardingBuilder;
use Dmitryisaenko\LaraFoundry\Seo\LaraFoundrySeo;

/**
 * One-call wiring helpers a host invokes for the onboarding checklist (phase 5.4).
 *
 * Parallel to {@see LaraFoundrySeo}
 * and {@see LaraFoundryNavigation}: the host
 * merges {@see sharedProps()} into its `HandleInertiaRequests::share`, and every
 * page receives the resolved `onboarding` prop the host's home page renders
 * through the `<OnboardingChecklist>` component. Unlike the SEO kit the core does
 * NOT mount the component anywhere — the checklist is a page element the host
 * places on its own home page, so it is only ever shown where the host wants it.
 *
 * The prop is null for anyone with nobody to onboard (guest / super-admin) and
 * carries a `dismissed` flag for a user who has hidden it — the component renders
 * nothing in either case, so wiring the prop app-wide is always safe.
 */
class LaraFoundryOnboarding
{
    /**
     * Inertia shared props: the resolved checklist for the current user.
     *
     * Lazily evaluated — the closure runs only when a page serialises it.
     *
     * @return array<string, \Closure>
     */
    public static function sharedProps(): array
    {
        return [
            'onboarding' => fn () => app(OnboardingBuilder::class)->build(auth()->user()),
        ];
    }
}
