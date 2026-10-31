<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the first-login setup wizard's marks to `users` (v0.49.0).
 *
 * `setup_done` is `{part: finished at}` - one entry per part of the wizard the
 * user has finished ("Done" or "Don't show again"). A part, not a step: a step
 * added to a part later is asked only of people who have not finished it yet.
 *
 * Guarded on the column: a host that kept these marks under the same name
 * before the core took the wizard over keeps its column and its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'setup_done')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('setup_done')->nullable();
        });
    }

    /**
     * Leaves the column: `up()` may not have created it (a host's own, kept), and a
     * rollback would then wipe every user's marks (review 2026-10-31). An unused
     * nullable column costs nothing.
     */
    public function down(): void
    {
        //
    }
};
