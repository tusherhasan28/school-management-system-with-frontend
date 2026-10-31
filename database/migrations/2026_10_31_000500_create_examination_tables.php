<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 4: examinations, configurable grading and versioned results.
 *
 * - Grading scales are per school and fully configurable (bands + rules JSON).
 *   No national formula is hardcoded; presets are only starting points.
 * - Every calculation is stored as a numbered version with a snapshot of the
 *   grading configuration and exam setup it used, plus a hash of the marks, so a
 *   published result can be audited and reproduced later.
 * - Marks and results are DECIMAL, never FLOAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_types', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 100);
            $table->string('name_bn', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->json('rules');
            $table->boolean('is_default')->default(false);
            $table->dateTime('locked_at')->nullable(); // set when a published result used it
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('grading_scale_items', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('grading_scale_id', 'grading_scales');
            $table->string('grade', 10);
            $table->decimal('min_percentage', 5, 2);
            $table->decimal('max_percentage', 5, 2);
            $table->decimal('grade_point', 4, 2);
            $table->boolean('is_fail')->default(false);
            $table->string('remarks', 100)->nullable();
            $table->string('remarks_bn', 100)->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'grading_scale_id', 'grade'], 'grading_scale_items_grade_unique');
        });

        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('exam_type_id', 'exam_types', nullable: true);
            $table->tenantForeign('grading_scale_id', 'grading_scales');
            $table->string('name', 150);
            $table->string('name_bn', 150)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'marks_entry', 'calculated', 'approved', 'published'])->default('draft');
            $table->unsignedBigInteger('current_calculation_id')->nullable();
            $table->tenantForeign('approved_by', 'users', nullable: true);
            $table->dateTime('approved_at')->nullable();
            $table->tenantForeign('published_by', 'users', nullable: true);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'academic_session_id', 'status']);
        });

        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('examination_id', 'examinations');
            $table->tenantForeign('class_id', 'classes');
            $table->tenantForeign('subject_id', 'subjects');
            // compulsory: counts for GPA and pass/fail; optional: "4th subject";
            // non_gpa: graded and printed, excluded from GPA, totals and pass/fail.
            $table->enum('subject_type', ['compulsory', 'optional', 'non_gpa'])->default('compulsory');
            $table->date('exam_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('full_marks', 7, 2)->default(0); // sum of components, kept in sync by the service
            $table->decimal('pass_marks', 7, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'examination_id', 'class_id', 'subject_id'], 'exam_subjects_unique');
        });

        Schema::create('exam_subject_components', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('exam_subject_id', 'exam_subjects');
            $table->string('name', 60);
            $table->string('name_bn', 60)->nullable();
            $table->decimal('full_marks', 7, 2);
            $table->decimal('pass_marks', 7, 2)->nullable(); // separate pass mark for this part, if any
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
        });

        // One mark sheet per exam subject and section: draft -> submitted -> verified.
        Schema::create('exam_mark_sheets', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('exam_subject_id', 'exam_subjects');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id');
            $table->enum('status', ['draft', 'submitted', 'verified'])->default('draft');
            $table->tenantForeign('submitted_by', 'users', nullable: true);
            $table->dateTime('submitted_at')->nullable();
            $table->tenantForeign('verified_by', 'users', nullable: true);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'exam_subject_id', 'section_id'], 'exam_mark_sheets_unique');
            $table->foreign(['school_id', 'class_id', 'section_id'], 'exam_mark_sheets_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('exam_marks', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('exam_subject_id', 'exam_subjects');
            $table->tenantForeign('exam_mark_sheet_id', 'exam_mark_sheets');
            $table->tenantForeign('student_id', 'students');
            $table->enum('status', ['present', 'absent', 'exempt'])->default('present');
            $table->decimal('total', 7, 2)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->tenantForeign('entered_by', 'users', nullable: true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'exam_subject_id', 'student_id'], 'exam_marks_unique');
        });

        Schema::create('exam_mark_parts', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('exam_mark_id', 'exam_marks');
            $table->tenantForeign('exam_subject_component_id', 'exam_subject_components');
            $table->decimal('marks', 7, 2);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'exam_mark_id', 'exam_subject_component_id'], 'exam_mark_parts_unique');
        });

        Schema::create('result_calculations', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('examination_id', 'examinations');
            $table->unsignedInteger('version');
            $table->string('engine_version', 20);
            $table->json('grading_snapshot'); // scale bands + rules used
            $table->json('setup_snapshot');   // exam subjects + components used
            $table->char('config_hash', 64);
            $table->char('input_hash', 64);   // hash of every mark used
            $table->unsignedInteger('student_count')->default(0);
            $table->tenantForeign('calculated_by', 'users', nullable: true);
            $table->dateTime('calculated_at');
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'examination_id', 'version'], 'result_calculations_version_unique');
        });

        Schema::table('examinations', function (Blueprint $table) {
            $table->foreign(['school_id', 'current_calculation_id'], 'examinations_current_calculation_fk')
                ->references(['school_id', 'id'])->on('result_calculations')->restrictOnDelete();
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('result_calculation_id', 'result_calculations');
            $table->tenantForeign('examination_id', 'examinations');
            $table->tenantForeign('student_id', 'students');
            $table->tenantForeign('class_id', 'classes');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->decimal('full_marks', 9, 2)->default(0);
            $table->decimal('obtained_marks', 9, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('gpa', 4, 2)->nullable();
            $table->decimal('gpa_without_optional', 4, 2)->nullable();
            $table->string('letter_grade', 10)->nullable();
            $table->enum('result_status', ['pass', 'fail', 'incomplete']);
            $table->unsignedTinyInteger('failed_subjects')->default(0);
            $table->unsignedInteger('class_position')->nullable();
            $table->unsignedInteger('section_position')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'result_calculation_id', 'student_id'], 'exam_results_unique');
            $table->index(['school_id', 'examination_id', 'student_id']);
            $table->foreign(['school_id', 'class_id', 'section_id'], 'exam_results_section_fk')
                ->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
        });

        Schema::create('exam_subject_results', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('exam_result_id', 'exam_results');
            $table->tenantForeign('exam_subject_id', 'exam_subjects');
            $table->tenantForeign('subject_id', 'subjects');
            $table->enum('status', ['present', 'absent']);
            $table->enum('counted_as', ['compulsory', 'optional', 'non_gpa']);
            $table->decimal('full_marks', 7, 2);
            $table->decimal('obtained_marks', 7, 2);
            $table->decimal('percentage', 5, 2);
            $table->string('grade', 10);
            $table->decimal('grade_point', 4, 2);
            $table->boolean('passed');
            $table->json('components'); // [{name, full, pass, marks, passed}]
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'exam_result_id', 'exam_subject_id'], 'exam_subject_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_subject_results');
        Schema::dropIfExists('exam_results');
        Schema::table('examinations', fn (Blueprint $table) => $table->dropForeign('examinations_current_calculation_fk'));
        Schema::dropIfExists('result_calculations');
        Schema::dropIfExists('exam_mark_parts');
        Schema::dropIfExists('exam_marks');
        Schema::dropIfExists('exam_mark_sheets');
        Schema::dropIfExists('exam_subject_components');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('examinations');
        Schema::dropIfExists('grading_scale_items');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('exam_types');
    }
};
