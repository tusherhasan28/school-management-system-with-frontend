<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure (Milestone 2). Class names, stages and subjects are data,
 * not code: every school configures its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sequences', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('sequence_key', 80);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['school_id', 'sequence_key']);
        });

        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->enum('status', ['planned', 'active', 'completed', 'archived'])->default('planned');
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'is_current']);
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 100);
            $table->string('name_bn', 100)->nullable();
            $table->string('code', 40)->nullable();
            $table->smallInteger('level_order')->default(0);
            $table->enum('education_stage', ['early_years', 'primary', 'secondary', 'higher_secondary', 'college', 'madrasa', 'other'])->default('other');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'level_order']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('class_id', 'classes');
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'class_id', 'name']);
            // Target for (school_id, class_id, section_id) FKs: a section must belong to the referenced class.
            $table->unique(['school_id', 'class_id', 'id'], 'sections_school_class_id_unique');
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name', 150);
            $table->string('name_bn', 150)->nullable();
            $table->string('code', 40)->nullable();
            $table->enum('subject_type', ['compulsory', 'optional', 'elective', 'practical', 'other'])->default('compulsory');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'name']);
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('academic_session_id', 'academic_sessions');
            $table->tenantForeign('class_id', 'classes');
            $table->tenantForeign('subject_id', 'subjects');
            $table->decimal('full_marks', 7, 2)->default(100);
            $table->decimal('pass_marks', 7, 2)->default(33);
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['school_id', 'academic_session_id', 'class_id', 'subject_id'], 'class_subjects_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('academic_sessions');
        Schema::dropIfExists('tenant_sequences');
    }
};
