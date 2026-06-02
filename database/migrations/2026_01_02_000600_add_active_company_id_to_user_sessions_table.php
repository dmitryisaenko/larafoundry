<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the active-company binding to `user_sessions` (phase 1.2).
 *
 * Closes the seam left open in phase 1.1 (decision D5): `user_sessions` shipped
 * without this column on purpose, so it could arrive now with a REAL FK to
 * `companies` rather than as a dead integer field. This is what makes the
 * "active company is per-device" model work — each tracked session remembers
 * which company that device is currently acting as.
 *
 * `nullOnDelete`: deleting a company simply clears it from any session that had
 * it active (the SetActiveTenant middleware then re-resolves the next one).
 * Guarded so the migration is idempotent and tolerant of re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_sessions') || Schema::hasColumn('user_sessions', 'active_company_id')) {
            return;
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->foreignId('active_company_id')
                ->nullable()
                ->after('login_method')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_sessions', 'active_company_id')) {
            return;
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_company_id');
        });
    }
};
