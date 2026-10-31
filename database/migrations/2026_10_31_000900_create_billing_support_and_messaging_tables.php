<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 7: SaaS billing (plans, features, subscriptions, platform
 * invoices/payments), support (tickets, time-limited access grants) and
 * messaging (templates, delivery logs, SMS provider settings, in-app
 * notifications).
 *
 * Billing and support tables are CENTRAL: they belong to the platform and
 * reference schools.id with a plain foreign key (no tenant scope). Tenant
 * screens read them with an explicit school_id taken from the resolved host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 100);
            $table->string('name_bn', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->default(0);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true); // listed on the pricing page
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->json('feature_value')->nullable(); // limits: integer or null (= unlimited); switches: true/false
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            // trialing → active → past_due (grace) → suspended; cancelled; expired (ended without renewal).
            $table->enum('status', ['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired']);
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->decimal('amount', 12, 2);
            $table->dateTime('starts_at');
            $table->dateTime('current_period_start');
            $table->dateTime('current_period_end');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('grace_ends_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->restrictOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->string('description', 255);
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->char('currency', 3)->default('BDT');
            $table->enum('status', ['unpaid', 'paid', 'void'])->default('unpaid');
            $table->dateTime('issued_at');
            $table->dateTime('due_at');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            // One renewal invoice per subscription period (re-runs are harmless).
            $table->string('period_key', 80)->nullable()->unique();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('platform_invoice_id')->constrained('platform_invoices')->restrictOnDelete();
            $table->enum('method', ['bank', 'mobile_banking', 'cash', 'cheque', 'online']);
            $table->string('gateway', 50)->default('manual');
            $table->string('transaction_id', 150)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('BDT');
            $table->enum('status', ['pending', 'successful', 'failed', 'refunded'])->default('successful');
            $table->dateTime('received_at');
            $table->foreignId('recorded_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'transaction_id']);
            $table->index(['school_id', 'received_at']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->unsignedBigInteger('created_by_user_id')->nullable(); // tenant users.id
            $table->foreignId('assigned_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->string('subject', 190);
            $table->enum('category', ['technical', 'billing', 'data', 'feature', 'other'])->default('technical');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'in_progress', 'waiting', 'resolved', 'closed'])->default('open');
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['status', 'last_activity_at']);
            $table->foreign(['school_id', 'created_by_user_id'], 'support_tickets_user_fk')->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->enum('sender_type', ['platform', 'school']);
            $table->unsignedBigInteger('sender_id');
            $table->string('sender_name', 160);
            $table->text('message');
            $table->boolean('is_internal')->default(false); // platform-only note
            $table->timestamp('created_at')->nullable();

            $table->index(['support_ticket_id', 'id']);
        });

        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->string('reason', 500);
            $table->unsignedSmallInteger('duration_hours');
            $table->enum('status', ['requested', 'approved', 'rejected', 'revoked', 'expired'])->default('requested');
            $table->unsignedBigInteger('decided_by_user_id')->nullable(); // tenant users.id
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['platform_user_id', 'status']);
            $table->foreign(['school_id', 'decided_by_user_id'], 'support_grants_user_fk')->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // ---- Tenant tables -------------------------------------------------

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('template_key', 80);
            $table->enum('channel', ['sms', 'mail', 'database']);
            $table->string('subject', 190)->nullable();
            $table->string('subject_bn', 190)->nullable();
            $table->text('body');
            $table->text('body_bn')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'template_key', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->uuid('batch_id')->nullable(); // one bulk send
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->enum('channel', ['sms', 'mail', 'database']);
            $table->string('recipient', 190); // phone or email (needed for retry)
            $table->string('recipient_name', 190)->nullable();
            $table->string('template_key', 80)->nullable();
            $table->char('locale', 2)->default('bn');
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->unsignedSmallInteger('segments')->default(1); // SMS parts
            $table->enum('status', ['queued', 'sent', 'failed', 'skipped'])->default('queued');
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 150)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'channel', 'created_at']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'batch_id']);
        });

        Schema::create('sms_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('provider', 50);
            $table->string('sender_id', 30)->nullable();
            $table->text('credentials')->nullable(); // encrypted JSON, never sent to the browser
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'provider']);
        });

        // In-app notification centre (Laravel database notifications). Notifiables are
        // tenant users or platform users; rows are only ever read through their owner.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('sms_provider_accounts');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('platform_payments');
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
