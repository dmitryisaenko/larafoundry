<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal access tokens (Sanctum) — owned by the core.
 *
 * Sanctum ships this table only as a publishable migration (`sanctum-migrations`),
 * so by default it never runs. The core, however, needs Sanctum's `auth:sanctum`
 * guard available out of the box for its API seam (QR-login verify and any future
 * mobile endpoints), so it carries the table in its own auto-loaded migrations —
 * a host gets it on a plain `php artisan migrate`, no extra publish step.
 *
 * Guarded with `hasTable` so a host that already ran Sanctum's own published
 * migration (or carries its own) does not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Symmetric with up(): only drop the table if THIS migration created it.
        // A host that also published Sanctum's own PAT migration owns the table
        // through that file — rolling this one back must not drop it from under
        // the host's migration. The migrations table tells us who created it:
        // if Sanctum's create migration ran, leave the table for it to drop.
        if ($this->sanctumOwnsTable()) {
            return;
        }

        Schema::dropIfExists('personal_access_tokens');
    }

    /**
     * Whether a Sanctum-style create-PAT migration is recorded in the migrations
     * table (the host published and ran Sanctum's own copy), in which case that
     * file owns the table's lifecycle, not this one.
     */
    protected function sanctumOwnsTable(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->where('migration', 'like', '%_create_personal_access_tokens_table')
            ->where('migration', '!=', '2026_01_08_001700_create_personal_access_tokens_table')
            ->exists();
    }
};
