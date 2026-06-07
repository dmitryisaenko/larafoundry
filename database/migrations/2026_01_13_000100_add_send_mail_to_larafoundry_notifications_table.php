<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the broadcast email opt-in flag (phase 5.1, decision D-5.1-14).
 *
 * A super-admin broadcast delivers in-app by default; ticking "also email"
 * sets this flag, and the chunked fan-out job then queues one mail per recipient
 * (still gated by the global `channels` master switch). Defaults false so every
 * existing and future broadcast stays in-app only unless the operator opts in —
 * no surprise mass mailings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('larafoundry_notifications', function (Blueprint $table) {
            $table->boolean('send_mail')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('larafoundry_notifications', function (Blueprint $table) {
            $table->dropColumn('send_mail');
        });
    }
};
