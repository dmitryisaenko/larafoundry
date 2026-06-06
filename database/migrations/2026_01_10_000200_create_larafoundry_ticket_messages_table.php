<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ticket conversation messages (phase 4.2).
 *
 * One row per reply, by the ticket owner or the operator. Cascades on ticket
 * deletion so a removed ticket leaves no orphan messages (the policy forbids
 * user/operator deletion in v1, but a host GDPR erasure or test teardown still
 * relies on the cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained('larafoundry_tickets')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->index();
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_ticket_messages');
    }
};
