<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the optional session PIN to `users` (phase 1.4).
 *
 * PIN-lock is a Telegram-style quick re-entry for an already-authenticated
 * session: instead of a full re-login after idle, the user types a short PIN.
 * The PIN is a per-user secret (one PIN, set from the profile), stored hashed
 * (bcrypt) — never in clear. Whether a given session is currently locked is a
 * per-session flag, added separately to `user_sessions`.
 *
 * Guarded so the migration is idempotent and tolerant of re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'pin_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_code')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'pin_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_code');
        });
    }
};
