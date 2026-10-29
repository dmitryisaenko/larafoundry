<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a system notification is ABOUT (v0.44.0): an optional polymorphic subject.
 *
 * A domain notification usually concerns one record of the host - an order that
 * shipped, a job that finished. Keeping that record as a real indexed reference
 * (rather than an id buried in the `data` JSON) lets the host find every
 * notification of one record ("the notices of this job") and draw it with the
 * record's CURRENT state (a title renamed since) without scanning JSON.
 *
 * Additive and nullable: admin broadcasts and every existing system notification
 * have no subject, and the core itself never requires one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('larafoundry_notifications', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->after('data');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('larafoundry_notifications', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
