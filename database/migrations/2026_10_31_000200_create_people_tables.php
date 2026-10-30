<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff, guardians, students, their links and enrollment history.
 * Identifiers such as NID and birth registration numbers are stored encrypted
 * (Laravel "encrypted" cast) and never logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->enum('staff_type', ['teacher', 'staff'])->default('teacher');
            $table->string('employee_no', 60);
            $table->string('full_name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'not_specified'])->default('not_specified');
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('designation', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('qualification', 190)->nullable();
            $table->date('joining_date')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->tenantKeys();
            $table->unique(['school_id', 'employee_no']);
            $table->unique(['school_id', 'user_id']);
            $table->index(['school_id', 'staff_type', 'status']);
            $table->index(['school_id', 'full_name']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('full_name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->string('phone', 30);
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->text('address')->nullable();
            $table->text('national_id')->nullable(); // encrypted cast
            $table->timestamps();
            $table->softDeletes();

            $table->tenantKeys();
            $table->unique(['school_id', 'user_id']);
            // Not unique: two guardians (e.g. parents) may share a phone. Imports warn on duplicates.
            $table->index(['school_id', 'phone']);
            $table->index(['school_id', 'full_name']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('admission_no', 60);
            $table->string('full_name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'not_specified'])->default('not_specified');
            $table->string('blood_group', 5)->nullable();
            $table->string('religion', 60)->nullable();
            $table->string('nationality', 80)->nullable()->default('Bangladeshi');
            $table->text('birth_registration_no')->nullable(); // encrypted cast
            $table->string('photo_path', 500)->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->date('admission_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'graduated', 'transferred', 'withdrawn'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->tenantKeys();
            $table->unique(['school_id', 'admission_no']);
            $table->unique(['school_id', 'user_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'full_name']);
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('guardian_id', 'guardians');
            $table->string('relationship', 60);
            $table->boolean('is_primary')->default(false);
            $table->boolean('can_pickup')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'student_id', 'guardian_id']);
            $table->index(['school_id', 'guardian_id']);
        });

        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->string('roll_no', 40)->nullable();
            $table->enum('status', ['enrolled', 'promoted', 'repeated', 'transferred', 'withdrawn', 'graduated'])->default('enrolled');
            $table->date('enrolled_at');
            $table->date('ended_at')->nullable();
            $table->string('end_reason', 255)->nullable();
            $table->timestamps();

            $table->tenantKeys();
            // One enrollment per student per session; history lives across sessions.
            $table->unique(['school_id', 'student_id', 'academic_session_id'], 'student_enrollments_one_per_session');
            $table->unique(['school_id', 'academic_session_id', 'class_id', 'section_id', 'roll_no'], 'student_enrollments_roll_unique');
            $table->index(['school_id', 'academic_session_id', 'class_id', 'section_id'], 'student_enrollments_class_idx');

            // The section must belong to the same school AND the same class.
            $table->foreign(['school_id', 'class_id', 'section_id'], 'student_enrollments_section_tenant_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('staff_members');
    }
};
