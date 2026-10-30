<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central: a school's request to join, reviewed by platform staff.
        Schema::create('school_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('institution_name', 190);
            $table->string('institution_name_bn', 190)->nullable();
            $table->string('requested_slug', 63);
            $table->enum('institution_type', ['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'])->default('school');
            $table->string('contact_name', 160);
            $table->string('email', 190);
            $table->string('phone', 30);
            $table->string('district', 100)->nullable();
            $table->text('address')->nullable();
            $table->unsignedInteger('approx_students')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('requested_slug');
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users');
            $table->char('token_hash', 64)->unique(); // sha256 of the emailed token
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->tenantForeign('invited_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'user_id']);
        });

        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('application_no', 60);
            $table->tenantForeign('academic_session_id', 'academic_sessions', nullable: true);
            $table->tenantForeign('applied_class_id', 'classes', nullable: true);
            $table->string('applicant_name', 160);
            $table->string('applicant_name_bn', 160)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'not_specified'])->default('not_specified');
            $table->string('guardian_name', 160);
            $table->string('guardian_relationship', 60)->default('guardian');
            $table->string('guardian_phone', 30);
            $table->string('guardian_email', 190)->nullable();
            $table->text('address')->nullable();
            $table->string('previous_school', 190)->nullable();
            $table->json('application_data')->nullable();
            $table->enum('status', ['submitted', 'under_review', 'approved', 'rejected', 'enrolled'])->default('submitted');
            $table->tenantForeign('reviewed_by', 'users', nullable: true);
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->tenantForeign('student_id', 'students', nullable: true);
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'application_no']);
            $table->index(['school_id', 'status', 'submitted_at']);
            $table->index(['school_id', 'guardian_phone']);
        });

        Schema::create('data_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users');
            $table->string('import_type', 40);
            $table->string('original_name', 190);
            $table->string('source_path', 500);
            $table->json('options')->nullable();
            $table->enum('status', ['validated', 'queued', 'processing', 'completed', 'failed'])->default('validated');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('preview')->nullable();
            $table->string('error_report_path', 500)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_jobs');
        Schema::dropIfExists('admission_applications');
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('school_registrations');
    }
};
