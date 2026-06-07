<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable legal pages — Terms, Privacy, Cookie policy (phase 5.3).
 *
 * One row per page `slug` (terms, privacy, cookies). The row holds ONLY a
 * super-admin's published page; the editable slugs and their placeholder defaults
 * live in the `config('larafoundry-legal.pages')` registry, so this table is empty
 * until an operator publishes a page. The title/body columns are JSON keyed by
 * locale ({"en": "...", "uk": "..."}). Global by design — no company_id: the editor
 * is super-admin-only, and a tenant-scoped HTML editor would be an XSS surface for
 * tenant users (same reasoning as the email-template editor, phase 5.1).
 *
 * `version` is bumped by the operator when a change requires re-acceptance (feeds
 * the terms re-accept gate, phase 5.3 part B). `is_published` gates public
 * visibility; `published_at` records when it last went live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('title_translations');
            $table->json('body_html_translations');
            $table->unsignedInteger('version')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_legal_pages');
    }
};
