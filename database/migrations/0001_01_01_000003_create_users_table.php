<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant users: staff, teachers, students and guardians who log in to a school.
 * Email and phone are unique per school, not globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->string('preferred_locale', 10)->default('bn');
            $table->enum('status', ['invited', 'active', 'disabled', 'locked'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->tenantKeys();
            $table->unique(['school_id', 'email']);
            $table->unique(['school_id', 'phone']);
            $table->index(['school_id', 'status']);
        });

        // Password reset tokens are keyed by school as well as email: the same
        // address may belong to different people in different schools.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['school_id', 'email']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
