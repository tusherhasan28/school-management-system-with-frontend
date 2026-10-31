<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6: the public school website, notices, events and gallery.
 *
 * Website text is bilingual (English column + _bn column). Page bodies are
 * Markdown rendered without raw HTML, so no editor can inject scripts.
 * Nothing here references students or guardians: the website is built only
 * from content the school writes for the public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('template', 30)->default('classic');
            $table->string('theme_color', 7)->default('#0f766e');
            $table->string('default_language', 2)->default('bn');
            $table->boolean('is_published')->default(false);
            $table->string('eiin', 20)->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('hero_image_path', 500)->nullable();
            $table->string('principal_photo_path', 500)->nullable();
            foreach (['tagline', 'hero_title', 'principal_name', 'office_hours'] as $field) {
                $table->string($field, 190)->nullable();
                $table->string($field.'_bn', 190)->nullable();
            }
            foreach (['hero_text', 'about', 'principal_message'] as $field) {
                $table->text($field)->nullable();
                $table->text($field.'_bn')->nullable();
            }
            $table->string('seo_description', 320)->nullable();
            $table->string('seo_description_bn', 320)->nullable();
            $table->string('facebook_url', 255)->nullable();
            $table->string('youtube_url', 255)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->boolean('show_teachers')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique('school_id');
        });

        Schema::create('website_pages', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('slug', 120);
            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->mediumText('body')->nullable();
            $table->mediumText('body_bn')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->tenantForeign('updated_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'slug']);
        });

        Schema::create('website_menu_items', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->enum('location', ['header', 'footer'])->default('header');
            $table->string('label', 120);
            $table->string('label_bn', 120)->nullable();
            $table->enum('link_type', ['builtin', 'page', 'url']);
            $table->string('builtin', 30)->nullable();
            $table->tenantForeign('website_page_id', 'website_pages', nullable: true);
            $table->string('url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'location', 'sort_order']);
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->mediumText('body')->nullable();
            $table->mediumText('body_bn')->nullable();
            // Any of: public (website + everyone signed in), staff, parents, students.
            $table->json('audiences');
            $table->boolean('is_pinned')->default(false);
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 190)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'status', 'publish_at']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->time('starts_at')->nullable();
            $table->string('location', 190)->nullable();
            $table->boolean('is_public')->default(true);
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'starts_on']);
        });

        Schema::create('gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->string('description', 500)->nullable();
            $table->date('taken_on')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
        });

        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('gallery_album_id', 'gallery_albums');
            $table->string('path', 500);
            $table->string('thumb_path', 500);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('caption', 190)->nullable();
            $table->string('caption_bn', 190)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'gallery_album_id', 'sort_order']);
        });

        Schema::table('staff_members', function (Blueprint $table) {
            // Privacy by default: a teacher appears on the website only when the school says so.
            $table->boolean('show_on_website')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', fn (Blueprint $table) => $table->dropColumn('show_on_website'));

        foreach (['gallery_photos', 'gallery_albums', 'events', 'notices', 'website_menu_items', 'website_pages', 'website_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
