<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3: teacher assignments, timetable, holidays, attendance, homework.
 * Every reference is a composite (school_id, x_id) foreign key; references to
 * a section also carry class_id so the section must belong to that class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('staff_member_id', 'staff_members');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id')->nullable(); // null = every section of the class
            $table->tenantForeign('subject_id', 'subjects', nullable: true); // null = class teacher (no subject)
            $table->enum('assignment_type', ['class_teacher', 'subject_teacher']);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'academic_session_id', 'staff_member_id'], 'teacher_assignments_teacher_idx');
            $table->index(['school_id', 'academic_session_id', 'class_id', 'section_id'], 'teacher_assignments_section_idx');
            $table->foreign(['school_id', 'class_id', 'section_id'], 'teacher_assignments_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('class_routines', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id');
            $table->tenantForeign('subject_id', 'subjects');
            $table->tenantForeign('staff_member_id', 'staff_members', nullable: true);
            $table->unsignedTinyInteger('day_of_week'); // ISO-8601: 1 = Monday … 7 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('room', 80)->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'academic_session_id', 'section_id', 'day_of_week'], 'class_routines_section_day_idx');
            $table->index(['school_id', 'academic_session_id', 'staff_member_id', 'day_of_week'], 'class_routines_teacher_day_idx');
            $table->foreign(['school_id', 'class_id', 'section_id'], 'class_routines_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('holiday_type', ['public', 'religious', 'school', 'exam_break', 'other'])->default('public');
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'start_date', 'end_date']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id');
            $table->date('attendance_date');
            // "daily" or "routine:{id}" (period-wise). Part of the duplicate guard.
            $table->string('period_key', 40)->default('daily');
            $table->tenantForeign('class_routine_id', 'class_routines', nullable: true);
            $table->tenantForeign('taken_by', 'users', nullable: true);
            $table->dateTime('finalized_at')->nullable();
            $table->tenantForeign('finalized_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            // The duplicate guard: one attendance session per section, date and period.
            $table->unique(['school_id', 'section_id', 'attendance_date', 'period_key'], 'attendance_sessions_unique');
            $table->index(['school_id', 'attendance_date']);
            $table->foreign(['school_id', 'class_id', 'section_id'], 'attendance_sessions_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('student_attendance', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('attendance_session_id', 'attendance_sessions');
            $table->tenantForeign('student_id', 'students');
            $table->enum('status', ['present', 'absent', 'late', 'leave', 'half_day']);
            $table->string('remarks', 255)->nullable();
            $table->tenantForeign('marked_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            // One mark per student per attendance session.
            $table->unique(['school_id', 'attendance_session_id', 'student_id'], 'student_attendance_unique');
            $table->index(['school_id', 'student_id', 'status']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('student_attendance_id', 'student_attendance');
            $table->enum('old_status', ['present', 'absent', 'late', 'leave', 'half_day']);
            $table->enum('new_status', ['present', 'absent', 'late', 'leave', 'half_day']);
            $table->string('reason', 255);
            $table->tenantForeign('corrected_by', 'users', nullable: true);
            $table->timestamp('created_at')->nullable();

            $table->tenantKeys();
            $table->index(['school_id', 'student_attendance_id']);
        });

        Schema::create('homework', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id')->nullable(); // null = whole class
            $table->tenantForeign('subject_id', 'subjects');
            $table->tenantForeign('staff_member_id', 'staff_members', nullable: true);
            $table->string('title', 190);
            $table->text('instructions')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 190)->nullable();
            $table->date('assigned_on');
            $table->dateTime('due_at')->nullable();
            $table->decimal('max_marks', 7, 2)->nullable();
            $table->enum('status', ['draft', 'published', 'closed'])->default('published');
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'academic_session_id', 'class_id', 'section_id'], 'homework_class_idx');
            $table->index(['school_id', 'staff_member_id']);
            $table->foreign(['school_id', 'class_id', 'section_id'], 'homework_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('homework_id', 'homework');
            $table->tenantForeign('student_id', 'students');
            $table->enum('status', ['pending', 'submitted', 'late', 'graded', 'missing'])->default('pending');
            $table->dateTime('submitted_at')->nullable();
            $table->text('submission_text')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->decimal('marks', 7, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->tenantForeign('graded_by', 'users', nullable: true);
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'homework_id', 'student_id'], 'homework_submissions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
        Schema::dropIfExists('homework');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('student_attendance');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('class_routines');
        Schema::dropIfExists('teacher_assignments');
    }
};
