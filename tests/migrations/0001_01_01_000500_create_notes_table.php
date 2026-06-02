<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\Note;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only `notes` table for the {@see Note}
 * fixture, used to exercise BelongsToTenant.
 *
 * Carries both tenant foreign keys so the same fixture works in teams mode
 * (filters by company_id) and personal mode (filters by user_id). No DB-level FK
 * constraint on purpose — keeps this independent of migration ordering between
 * the test users table and the package's companies table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
