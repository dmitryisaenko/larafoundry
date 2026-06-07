<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `user_purged_at` to the host's `users` table (phase 5.3, account erasure).
 *
 * The companion to `user_deleted_at`: deletion sets `user_deleted_at` (the
 * reversible soft-delete that starts the grace clock); the daily purge command
 * stamps `user_purged_at` once the account has been irreversibly anonymised, so
 * a purged row is never reprocessed. Guarded with `hasColumn` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'user_purged_at')) {
                $table->timestamp('user_purged_at')->nullable()->after('user_deleted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'user_purged_at')) {
                $table->dropColumn('user_purged_at');
            }
        });
    }
};
