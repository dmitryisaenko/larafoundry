<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR cross-device sign-in requests (phase 1.4 part 2).
 *
 * Extracted from the donor's `sign_in_requests` table and hardened:
 *  - `token` stores a SHA-256 HASH, never the plaintext (donor kept it plain in
 *    the DB, so a DB leak compromised every live QR). The unguessable plaintext
 *    lives only in the QR image; the server compares hashes.
 *  - indexes on the columns the poll/verify/prune queries filter by (donor had
 *    only the unique token index).
 *  - `created_at` is kept and used in code to enforce an ABSOLUTE TTL cap, so a
 *    request that keeps sliding its `expires_at` forward still dies eventually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // SHA-256 hex digest of the plaintext token (64 chars).
            $table->string('token', 64)->unique();
            $table->dateTime('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('approved')->default(false);
            $table->dateTime('approved_at')->nullable();
            $table->string('approved_ip', 45)->nullable();
            $table->text('approved_user_agent')->nullable();
            $table->timestamps();

            // poll() filters by (approved, expires_at); prune by expires_at /
            // created_at; verify by the unique token (already indexed).
            $table->index(['approved', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_requests');
    }
};
