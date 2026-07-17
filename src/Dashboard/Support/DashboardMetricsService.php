<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Dashboard\Support;

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity;
use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as DefaultUser;

/**
 * Computes the FREE core's operator-dashboard metrics (phase 3.4).
 *
 * Kept OUT of the widget provider so the SQL is testable in isolation and can be
 * wrapped in a cache later (`Cache::remember`) without touching the seam. Every
 * figure is a constant-query aggregate — one `SELECT … SUM(CASE WHEN …)` per
 * domain, never a per-row classification — so the page is O(1) regardless of how
 * many users or companies exist (no N+1, no per-row model instantiation).
 *
 * Deliberately revenue-agnostic: it counts users, companies and activity only.
 * Money is the paid billing add-on's territory (B9), injected through the
 * dashboard seam, never read here.
 *
 * Models come from config (the host's user / company classes), so the service
 * works against whatever a host has composed.
 */
class DashboardMetricsService
{
    /**
     * User metrics: totals, recent sign-ups, verification/block state and a
     * recently-active count — one aggregate query.
     *
     * "Active" means a tracked last_activity_at within the configured window
     * (`larafoundry.admin.active_within_days`, default 30) — the same column the
     * session tracker refreshes each request.
     *
     * @return array<string, int>
     */
    public function users(): array
    {
        $model = $this->userModel();

        $activeDays = (int) config('larafoundry.admin.active_within_days', 30);

        // Exclude the operator account from every user aggregate (config-gated),
        // so the dashboard "users" figures count ordinary users only.
        $row = $model::query()->withoutSuperAdmin()->selectRaw(
            'COUNT(*) as total, '
            .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_today, '
            .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_month, '
            .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_year, '
            .'SUM(CASE WHEN email_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified, '
            .'SUM(CASE WHEN user_blocked_at IS NOT NULL THEN 1 ELSE 0 END) as blocked, '
            .'SUM(CASE WHEN last_activity_at >= ? THEN 1 ELSE 0 END) as active',
            [
                now()->startOfDay()->toDateTimeString(),
                now()->startOfMonth()->toDateTimeString(),
                now()->startOfYear()->toDateTimeString(),
                now()->subDays($activeDays)->toDateTimeString(),
            ]
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'new_today' => (int) ($row->new_today ?? 0),
            'new_month' => (int) ($row->new_month ?? 0),
            'new_year' => (int) ($row->new_year ?? 0),
            'verified' => (int) ($row->verified ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'active' => (int) ($row->active ?? 0),
        ];
    }

    /**
     * Company metrics: totals, recent sign-ups and the subscription-status
     * breakdown — one aggregate query.
     *
     * The breakdown reproduces {@see SubscriptionStatus} semantics in SQL rather
     * than instantiating a value object per row: the same precedence (a live trial
     * beats an active subscription), the same "expiring" window
     * (`larafoundry.admin.subscription_expiring_within_days`) and the same
     * lapsed-vs-never distinction. The buckets are mutually exclusive and total
     * up to `total`. NULL date columns fall out of every `>`/`<=` comparison
     * (NULL is never true), so the explicit IS NULL / IS NOT NULL guards are the
     * only place nulls are handled — keeping the classification fail-closed.
     *
     * @return array<string, int>
     */
    public function companies(): array
    {
        $model = $this->companyModel();

        $days = (int) config('larafoundry.admin.subscription_expiring_within_days', 7);

        $now = now()->toDateTimeString();
        $soon = now()->addDays($days)->toDateTimeString();
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $row = $model::query()->selectRaw(
            'COUNT(*) as total, '
            .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_month, '
            // A live trial wins (matches SubscriptionStatus precedence).
            .'SUM(CASE WHEN trial_ends_at > ? THEN 1 ELSE 0 END) as on_trial, '
            // Active (not on trial) with the subscription end beyond the window.
            .'SUM(CASE WHEN (trial_ends_at IS NULL OR trial_ends_at <= ?) AND subscription_ends_at > ? THEN 1 ELSE 0 END) as active, '
            // Active but ending inside the window → expiring.
            .'SUM(CASE WHEN (trial_ends_at IS NULL OR trial_ends_at <= ?) AND subscription_ends_at > ? AND subscription_ends_at <= ? THEN 1 ELSE 0 END) as expiring, '
            // Lapsed: not on trial, no live subscription, but some billing date existed.
            .'SUM(CASE WHEN (trial_ends_at IS NULL OR trial_ends_at <= ?) AND (subscription_ends_at IS NULL OR subscription_ends_at <= ?) AND (subscription_ends_at IS NOT NULL OR trial_ends_at IS NOT NULL) THEN 1 ELSE 0 END) as expired, '
            // Never had a billing date at all.
            .'SUM(CASE WHEN trial_ends_at IS NULL AND subscription_ends_at IS NULL THEN 1 ELSE 0 END) as never_activated',
            [
                $monthStart,        // new_month
                $now,               // on_trial: trial_ends_at >
                $now, $soon,        // active: trial <=, subscription > soon
                $now, $now, $soon,  // expiring: trial <=, subscription > now, subscription <= soon
                $now, $now,         // expired: trial <=, subscription <=
            ]
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'new_month' => (int) ($row->new_month ?? 0),
            'on_trial' => (int) ($row->on_trial ?? 0),
            'active' => (int) ($row->active ?? 0),
            'expiring' => (int) ($row->expiring ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'never_activated' => (int) ($row->never_activated ?? 0),
        ];
    }

    /**
     * Activity metrics: a count of the last 24h of admin events plus a compact
     * recent feed for the dashboard panel.
     *
     * Scoped to the `admin` log name (the operator console's own audit trail,
     * phase 2.1) — not every domain event a host might log. The causer name is
     * eager-loaded through the morph-safe `user` relation (null for a non-user
     * causer) so the feed has no N+1.
     *
     * @return array{last_24h: int, recent: array<int, array<string, mixed>>}
     */
    public function activity(): array
    {
        // Clamp the configured feed size: a host typo can't pull an unbounded
        // feed on every dashboard render.
        $limit = max(1, min((int) config('larafoundry.admin.dashboard_activity_limit', 10), 100));

        $recent = Activity::query()
            ->where('log_name', 'admin')
            ->with('user:id,name,lastname')
            ->recent()
            ->limit($limit)
            ->get()
            ->map(fn (Activity $entry) => [
                'id' => $entry->getKey(),
                'description' => $entry->description,
                'causer' => $this->causerName($entry),
                'subject_id' => $entry->subject_id,
                'created_at' => optional($entry->created_at)->toIso8601String(),
            ])
            ->all();

        $last24h = Activity::query()
            ->where('log_name', 'admin')
            ->inLastHours(24)
            ->count();

        return [
            'last_24h' => $last24h,
            'recent' => $recent,
        ];
    }

    /**
     * A human label for the event's causer, or null when it was not a user.
     */
    protected function causerName(Activity $entry): ?string
    {
        $user = $entry->user;

        if ($user === null) {
            return null;
        }

        return trim((string) $user->getAttribute('name').' '.(string) $user->getAttribute('lastname')) ?: null;
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        return $model;
    }

    /**
     * @return class-string<Model>
     */
    protected function companyModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('larafoundry.tenancy.company_model');

        return $model;
    }
}
