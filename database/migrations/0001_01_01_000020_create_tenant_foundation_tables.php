<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant foundation: audit log, settings and campuses (reference module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('action', 120);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->tenantKeys();
            $table->index(['school_id', 'created_at']);
            $table->index(['school_id', 'subject_type', 'subject_id']);
        });

        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('setting_key', 120);
            $table->json('setting_value')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'setting_key']);
        });

        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->string('code', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campuses');
        Schema::dropIfExists('school_settings');
        Schema::dropIfExists('activity_logs');
    }
};
