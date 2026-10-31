<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5: fees, invoices, payments.
 *
 * - Money is DECIMAL(12,2) and computed in integer paisa (App\Support\Money).
 * - Financial rows are never deleted. Invoice items are append-only; a payment
 *   is immutable once successful; reversals/refunds are separate rows.
 * - An invoice's balance = sum(items) − allocations of successful, unreversed
 *   payments. paid_total/status on the invoice are caches recomputed inside the
 *   allocation transaction.
 * - Gateway transaction ids are unique per gateway, so a repeated callback can
 *   never create a second payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes', nullable: true); // null = every class
            $table->tenantForeign('fee_category_id', 'fee_categories');
            $table->string('name', 150);
            $table->decimal('amount', 12, 2);
            $table->enum('frequency', ['one_time', 'monthly', 'quarterly', 'half_yearly', 'yearly']);
            $table->char('start_period', 7); // YYYY-MM: first billing month
            // class: every enrolled student of the class; assigned: only students given this fee.
            $table->enum('applies_to', ['class', 'assigned'])->default('class');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'academic_session_id', 'class_id']);
        });

        // Opt-in fees (transport, hostel) and per-student amount overrides (0 = waived).
        Schema::create('student_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('fee_structure_id', 'fee_structures');
            $table->decimal('custom_amount', 12, 2)->nullable();
            $table->string('note', 190)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'student_id', 'fee_structure_id'], 'student_fee_assignments_unique');
        });

        // Discounts and scholarships, applied to invoice lines when invoices are generated.
        Schema::create('fee_discounts', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('fee_category_id', 'fee_categories', nullable: true); // null = every category
            $table->string('name', 120); // e.g. "Sibling discount", "Merit scholarship"
            $table->enum('kind', ['percent', 'fixed']);
            $table->decimal('value', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'student_id', 'academic_session_id']);
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('invoice_no', 40);
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes', nullable: true);
            $table->unsignedBigInteger('section_id')->nullable();
            $table->char('billing_period', 7)->nullable(); // YYYY-MM for generated invoices
            // Set for generated invoices and cleared on cancel: one live invoice per student per period.
            $table->string('generation_key', 40)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('total', 12, 2)->default(0);      // cache: sum of items
            $table->decimal('paid_total', 12, 2)->default(0); // cache: live allocations
            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])->default('unpaid');
            $table->string('notes', 255)->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->dateTime('cancelled_at')->nullable();
            $table->tenantForeign('cancelled_by', 'users', nullable: true);
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'invoice_no']);
            $table->unique(['school_id', 'student_id', 'generation_key'], 'fee_invoices_generation_unique');
            $table->index(['school_id', 'student_id', 'status', 'due_date'], 'fee_invoices_student_idx');
            $table->index(['school_id', 'status', 'due_date']);
            $table->foreign(['school_id', 'class_id', 'section_id'], 'fee_invoices_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        // Append-only. Late fees and waivers are new rows (waivers are negative).
        Schema::create('fee_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('fee_invoice_id', 'fee_invoices');
            $table->enum('kind', ['fee', 'late_fee', 'waiver', 'adjustment']);
            $table->tenantForeign('fee_category_id', 'fee_categories', nullable: true);
            $table->tenantForeign('fee_structure_id', 'fee_structures', nullable: true);
            $table->string('description', 190);
            $table->decimal('amount', 12, 2);           // before discount
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_note', 190)->nullable();
            $table->decimal('line_total', 12, 2);       // amount − discount (negative for waivers)
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamp('created_at')->nullable();

            $table->tenantKeys();
            $table->index(['school_id', 'fee_invoice_id', 'kind']);
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('receipt_no', 40)->nullable(); // assigned when the payment succeeds
            $table->tenantForeign('student_id', 'students');
            $table->enum('method', ['cash', 'bank', 'cheque', 'mobile_banking', 'online']);
            $table->string('reference', 120)->nullable();   // bank/cheque/mobile-banking reference typed by staff
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_transaction_id', 150)->nullable();
            $table->char('public_id', 26);                  // our reference sent to gateways
            $table->string('idempotency_key', 64)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('BDT');
            $table->enum('status', ['pending', 'successful', 'failed', 'cancelled'])->default('pending');
            $table->dateTime('received_at')->nullable();
            $table->tenantForeign('received_by', 'users', nullable: true);
            $table->json('gateway_payload')->nullable();     // sanitised
            $table->json('intended_allocations')->nullable(); // online: invoices to settle on success
            $table->string('notes', 255)->nullable();
            $table->unsignedSmallInteger('reconcile_attempts')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'receipt_no']);
            $table->unique('public_id');
            $table->unique(['school_id', 'idempotency_key']);
            $table->unique(['gateway', 'gateway_transaction_id'], 'fee_payments_gateway_tx_unique');
            $table->index(['school_id', 'status', 'received_at']);
            $table->index(['school_id', 'student_id']);
        });

        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('fee_payment_id', 'fee_payments');
            $table->tenantForeign('fee_invoice_id', 'fee_invoices');
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->tenantKeys();
            $table->unique(['school_id', 'fee_payment_id', 'fee_invoice_id'], 'fee_payment_allocations_unique');
            $table->index(['school_id', 'fee_invoice_id']);
        });

        // A whole payment reversed (entered by mistake, bounced cheque) or refunded to the family.
        Schema::create('fee_payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('fee_payment_id', 'fee_payments');
            $table->enum('type', ['reversal', 'refund']);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->string('refund_method', 40)->nullable();
            $table->string('refund_reference', 120)->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamp('created_at')->nullable();

            $table->tenantKeys();
            $table->unique(['school_id', 'fee_payment_id'], 'fee_payment_reversals_payment_unique');
        });

        // Per-school merchant credentials for an installed gateway adapter (encrypted).
        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('gateway', 40);
            $table->enum('mode', ['sandbox', 'live'])->default('sandbox');
            $table->text('credentials'); // encrypted JSON
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'gateway']);
        });
    }

    public function down(): void
    {
        foreach (['payment_gateway_accounts', 'fee_payment_reversals', 'fee_payment_allocations', 'fee_payments', 'fee_invoice_items', 'fee_invoices', 'fee_discounts', 'student_fee_assignments', 'fee_structures', 'fee_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
