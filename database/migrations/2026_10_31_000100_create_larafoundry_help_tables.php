<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The help (v0.48.0): sections, their articles, and the pictures the texts refer to.
 *
 * Every text is JSON keyed by locale on the row itself (as `larafoundry_legal_pages`),
 * so a section translated into two languages out of three is still one row.
 * `position` steps by ten, leaving room to insert between two rows. A section's
 * `key` is kept apart from its title: `?section=billing` survives a renamed tab.
 *
 * An article may carry a second text written for a phone (`mobile_body_translations`);
 * null where there is none, and the phone then shows the desktop text.
 *
 * A picture is a record (`ref` -> file), and a text holds only `[[img:ref]]`: the
 * file name is a uuid the operator never chooses, and replacing a screenshot is one
 * upload instead of an edit of every translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_help_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('title_translations');
            $table->json('blurb_translations');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index('position');
        });

        Schema::create('larafoundry_help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_section_id')
                ->constrained('larafoundry_help_sections')
                ->cascadeOnDelete();
            $table->json('heading_translations');
            $table->json('body_translations');
            $table->json('mobile_body_translations')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['help_section_id', 'position']);
        });

        Schema::create('larafoundry_help_images', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 64)->unique();
            $table->string('file', 128);
            $table->json('caption_translations')->nullable();
            $table->unsignedSmallInteger('max_width')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_help_images');
        Schema::dropIfExists('larafoundry_help_articles');
        Schema::dropIfExists('larafoundry_help_sections');
    }
};
