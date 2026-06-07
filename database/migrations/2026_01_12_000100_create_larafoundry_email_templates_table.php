<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable email templates (phase 5.1).
 *
 * One row per template `code` (welcome_email, password_reset, …). The row holds
 * ONLY a super-admin's override of that template; the shipped defaults live in
 * the `config('larafoundry-email.templates')` registry, so this table is empty
 * until someone edits a template. The subject/body columns are JSON keyed by
 * locale ({"en": "...", "uk": "..."}). Global by design — no company_id: the
 * editor is super-admin-only (decision D-5.1-3), a tenant-scoped HTML editor
 * would be an XSS surface for tenant users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->json('subject_translations');
            $table->json('body_html_translations');
            $table->json('body_text_translations');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_email_templates');
    }
};
