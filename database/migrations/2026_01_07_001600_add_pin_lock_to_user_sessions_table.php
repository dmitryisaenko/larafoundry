<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-session PIN-lock state to `user_sessions` (phase 1.4).
 *
 * Locking is per-session, not per-user: the donor model is "lock everywhere,
 * unlock per-device" — a manual lock flags every session of the user, an idle
 * timeout flags only the session that went idle, and entering the PIN unlocks
 * only the device it was entered on.
 *
 *  - pin_locked       — is this session currently waiting for the PIN.
 *  - pin_attempts     — consecutive failed PIN entries on this session.
 *  - pin_locked_until  — when set (future), the PIN entry is rate-limited until
 *                        then (the brute-force fix the donor lacked).
 *
 * Guarded so the migration is idempotent and tolerant of re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_sessions') || Schema::hasColumn('user_sessions', 'pin_locked')) {
            return;
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->boolean('pin_locked')->default(false)->after('last_route_name');
            $table->unsignedTinyInteger('pin_attempts')->default(0)->after('pin_locked');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_attempts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_sessions', 'pin_locked')) {
            return;
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn(['pin_locked', 'pin_attempts', 'pin_locked_until']);
        });
    }
};
