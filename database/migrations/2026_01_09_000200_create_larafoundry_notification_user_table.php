<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recipient pivot (phase 4.1): which users a notification was delivered to and
 * when each read it. `unique(notification_id, user_id)` is the race backstop so
 * a re-run broadcast cannot double-attach the same user; `read_at` null = unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_notification_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')
                ->constrained('larafoundry_notifications')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);
            // The hottest predicate: a user's unread count / inbox
            // (`user_id = ? AND read_at IS NULL`), polled by the bell.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_notification_user');
    }
};
