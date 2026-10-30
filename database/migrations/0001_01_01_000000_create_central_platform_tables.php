<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (platform) tables. These are not tenant scoped.
 * Plans, subscriptions, platform invoices and support arrive in Milestone 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->string('name_bn', 190)->nullable();
            $table->string('slug', 63)->unique();
            $table->enum('institution_type', ['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'])->default('school');
            $table->enum('status', ['pending', 'trial', 'active', 'suspended', 'cancelled', 'archived'])->default('pending');
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('district', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->char('country_code', 2)->default('BD');
            $table->string('timezone', 60)->default('Asia/Dhaka');
            $table->string('locale', 10)->default('bn');
            $table->char('currency', 3)->default('BDT');
            $table->string('logo_path', 500)->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('school_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('domain', 190)->unique();
            // One primary per school is enforced in SchoolDomainService (no partial unique index in MySQL).
            $table->boolean('is_primary')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->enum('role', ['owner', 'administrator', 'billing_support', 'support_agent', 'auditor'])->default('support_agent');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('platform_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            $table->string('action', 120);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['school_id', 'created_at']);
            $table->index(['platform_user_id', 'created_at']);
        });

        Schema::create('platform_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_tokens');
        Schema::dropIfExists('platform_activity_logs');
        Schema::dropIfExists('platform_users');
        Schema::dropIfExists('school_domains');
        Schema::dropIfExists('schools');
    }
};
