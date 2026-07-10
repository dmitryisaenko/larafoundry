<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Models;

use Dmitryisaenko\LaraFoundry\Rules\HttpUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One social/profile link attached to a user (phase 3b).
 *
 * Stored in its own `larafoundry_user_social_links` table rather than a JSON
 * column (decision 2026-07-10) so a link is a first-class, queryable row with a
 * stable order (`sort`). The `platform` is validated against a whitelist and the
 * `url` is scheme-locked to http(s) on the way in (see StoreUserRequest +
 * {@see HttpUrl}), so a rendered `href` can
 * never carry a `javascript:`/`data:` payload.
 *
 * @property int $id
 * @property int $user_id
 * @property string $platform
 * @property string $url
 * @property int $sort
 */
class UserSocialLink extends Model
{
    protected $table = 'larafoundry_user_social_links';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'platform',
        'url',
        'sort',
    ];

    /**
     * The default recognised platforms.
     *
     * The SINGLE source of truth for both the write-side whitelist (validation)
     * and the front-end icon/select — the controller passes the resolved list to
     * the forms, and the request validates `platform` against it, so the two can
     * never drift. A host may extend the set by publishing
     * `larafoundry.profile.social_platforms`.
     *
     * @var list<string>
     */
    public const PLATFORMS = [
        'website',
        'twitter',
        'linkedin',
        'github',
        'facebook',
        'instagram',
        'telegram',
        'youtube',
    ];

    /**
     * The recognised platform slugs (config-overridable, defaulting to the const).
     *
     * @return list<string>
     */
    public static function platforms(): array
    {
        $configured = config('larafoundry.profile.social_platforms');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return self::PLATFORMS;
    }

    /**
     * The user this link belongs to (the host's configured user model).
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model);
    }
}
