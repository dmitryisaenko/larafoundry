<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A passwordless email sign-in (magic-link) token.
 *
 * The request side mints a random secret, mails a signed link carrying its
 * plaintext and persists only the SHA-256 HASH here; the verify side hashes the
 * link's token and matches it. Single-use: `consumed_at` is stamped atomically on
 * first verify, and a token is only ever honoured while {@see isUsable()} — not
 * consumed AND before both the sliding and the absolute expiry.
 *
 * The row is keyed to an EMAIL, not a user_id: the first successful click by an
 * unknown address registers it, so there is no account to reference at request
 * time (mirrors the OAuth "new account, email pre-verified" path).
 *
 * @property int $id
 * @property string $email
 * @property string $token_hash SHA-256 hash of the emailed secret
 * @property Carbon $expires_at
 * @property Carbon $absolute_expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $requested_ip
 */
class MagicLoginToken extends Model
{
    protected $table = 'larafoundry_magic_login_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'token_hash',
        'expires_at',
        'absolute_expires_at',
        'consumed_at',
        'requested_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Whether this token may still be exchanged for a login: never consumed and
     * before BOTH the sliding expiry and the absolute cap. Consuming is enforced
     * atomically at the query level in ConsumeMagicLinkAction; this predicate is
     * the same rule for reads/tests.
     */
    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && now()->lessThan($this->expires_at)
            && now()->lessThan($this->absolute_expires_at);
    }

    /**
     * Scope to the (normalised, lowercase) email — the request side lowercases
     * addresses, so the scope does too and the two agree.
     *
     * @param  Builder<MagicLoginToken>  $query
     * @return Builder<MagicLoginToken>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', Str::lower($email));
    }
}
