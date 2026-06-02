<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds LaraFoundry's identity columns onto the host's `users` table.
 *
 * The host owns the base `users` table (name/email/password/timestamps from the
 * default Laravel skeleton); this migration layers the identity slice the core
 * needs — extended name parts, phone, OAuth provider linkage, profile fields,
 * locale, blocking state, activity timestamps and the admin flag.
 *
 * Deliberately NOT here: company/tenancy columns (phase 1.2 adds them with a
 * real FK), role columns (phase 1.3), PIN (deferred), and Fortify's two-factor
 * columns (published by Fortify's own migration in the host — we don't own that
 * schema). Guards (`hasColumn`) keep this idempotent against hosts whose own
 * `users` migration already defines some of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each column is guarded so the migration is idempotent and tolerates a
        // host whose own `users` migration already defines some of these.
        Schema::table('users', function (Blueprint $table) {
            $add = function (string $column, callable $define): void {
                if (! Schema::hasColumn('users', $column)) {
                    $define();
                }
            };

            $add('lastname', fn () => $table->string('lastname')->nullable()->after('name'));
            $add('middlename', fn () => $table->string('middlename')->nullable()->after('lastname'));
            $add('phone', fn () => $table->string('phone')->nullable()->after('email'));
            $add('phone_verified_at', fn () => $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at'));

            // OAuth / Socialite linkage.
            $add('provider_id', fn () => $table->string('provider_id')->nullable()->after('password'));
            $add('provider_name', fn () => $table->string('provider_name')->nullable()->after('provider_id'));
            $add('provider_token', fn () => $table->text('provider_token')->nullable()->after('provider_name'));
            $add('provider_refresh_token', fn () => $table->text('provider_refresh_token')->nullable()->after('provider_token'));

            // Profile.
            $add('avatar', fn () => $table->string('avatar')->nullable()->after('provider_refresh_token'));
            $add('country', fn () => $table->string('country')->nullable()->after('avatar'));
            $add('locale', fn () => $table->string('locale')->nullable()->default(config('larafoundry.locale.default', 'en'))->after('country'));
            $add('sex', fn () => $table->string('sex')->nullable()->after('locale'));
            $add('birth_date', fn () => $table->date('birth_date')->nullable()->after('sex'));
            $add('ui_settings', fn () => $table->json('ui_settings')->nullable()->after('birth_date'));

            // Privilege flag (set explicitly, never mass-assigned).
            $add('is_admin', fn () => $table->boolean('is_admin')->default(false)->after('ui_settings'));

            // Blocking / lifecycle state.
            $add('user_blocked_at', fn () => $table->timestamp('user_blocked_at')->nullable()->after('is_admin'));
            $add('user_blocked_status', fn () => $table->string('user_blocked_status')->nullable()->after('user_blocked_at'));
            $add('block_code', fn () => $table->unsignedTinyInteger('block_code')->nullable()->after('user_blocked_status'));
            $add('user_deleted_at', fn () => $table->timestamp('user_deleted_at')->nullable()->after('block_code'));

            // Activity tracking.
            $add('last_login_at', fn () => $table->timestamp('last_login_at')->nullable()->after('user_deleted_at'));
            $add('last_activity_at', fn () => $table->timestamp('last_activity_at')->nullable()->after('last_login_at'));

            // OAuth-only users have no local password.
            $table->string('password')->nullable()->change();
        });

        // Index the provider pair we look users up by during OAuth callbacks.
        if (! $this->hasIndex('users_provider_index')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(['provider_name', 'provider_id'], 'users_provider_index');
            });
        }
    }

    protected function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('users'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_provider_index');

            $table->dropColumn([
                'middlename',
                'phone',
                'phone_verified_at',
                'provider_id',
                'provider_name',
                'provider_token',
                'provider_refresh_token',
                'avatar',
                'country',
                'locale',
                'sex',
                'birth_date',
                'ui_settings',
                'is_admin',
                'user_blocked_at',
                'user_blocked_status',
                'block_code',
                'user_deleted_at',
                'last_login_at',
                'last_activity_at',
            ]);

            // `lastname` is left in place: a host may have defined it itself.
        });
    }
};
