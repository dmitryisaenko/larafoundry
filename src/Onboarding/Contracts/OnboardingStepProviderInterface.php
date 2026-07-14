<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Onboarding\Contracts;

use Dmitryisaenko\LaraFoundry\Dashboard\Contracts\DashboardWidgetProviderInterface;
use Dmitryisaenko\LaraFoundry\Onboarding\Support\OnboardingBuilder;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contributes onboarding checklist steps for a tenant user (phase 5.4).
 *
 * The flat twin of the dashboard {@see DashboardWidgetProviderInterface}: the core
 * ships a provider for its own getting-started steps (profile, 2FA, company,
 * invite); a host or add-on registers more the same additive way it registers a
 * menu or a dashboard widget — so the checklist grows without the builder knowing
 * every step.
 *
 * A step is an associative array:
 *   [
 *     'key'         => string,  unique step identifier,
 *     'title'       => string,  i18n key (English-as-key), NOT a translated string,
 *     'description' => string,  i18n key,
 *     'cta_route'   => ?string, route NAME resolved to a url by the builder,
 *     'cta_label'   => ?string, i18n key,
 *     'complete'    => bool,    whether this user has finished the step,
 *     'order'       => int,     sort order across the merged set,
 *   ]
 *
 * Titles/labels are i18n keys the Vue layer translates at render, exactly like
 * menu labels and dashboard titles, so a locale switch re-paints without a reload.
 */
interface OnboardingStepProviderInterface
{
    /**
     * The steps this provider contributes for the given user.
     *
     * Completion is computed per user (the provider reads the user's real state);
     * the {@see OnboardingBuilder} merges, de-dups and tallies. A provider may
     * return fewer steps depending on context (e.g. teams-only steps are omitted
     * in personal mode).
     *
     * @return array<int, array<string, mixed>>
     */
    public function steps(Authenticatable $user): array;

    /**
     * Whether this provider contributes anything at all.
     */
    public function supports(): bool;

    /**
     * Provider ordering across the merged set (lower runs first). Step-level
     * `order` still sorts the final list; this only orders provider merge.
     */
    public function priority(): int;
}
