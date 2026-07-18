<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passwordless email sign-in (magic-link) tokens.
 *
 * Mirrors the QR `sign_in_requests` hardening: `token_hash` stores a SHA-256
 * digest of the secret that travels in the emailed link, never the plaintext, so
 * a DB leak cannot mint a working link. Two expiries — a sliding `expires_at`
 * and an absolute `absolute_expires_at` measured from creation (donor hole #4) —
 * plus a `consumed_at` stamp make each token single-use. Indexed on the columns
 * the verify/prune queries filter by; unlike the QR table this row is keyed to an
 * EMAIL (which may not yet own an account — the first click registers it), not a
 * user_id, so there is no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_magic_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            // SHA-256 hex digest of the plaintext token (64 chars).
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('absolute_expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            // verify filters by token_hash (unique, already indexed); prune by
            // consumed_at / absolute_expires_at / created_at.
            $table->index('absolute_expires_at');
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_magic_login_tokens');
    }
};
