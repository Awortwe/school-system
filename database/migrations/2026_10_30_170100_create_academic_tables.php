<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('sequence');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('reopening_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['academic_year_id', 'name']);
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('curriculum', 30)->default('GES');
            $table->string('campus', 30)->default('international');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['level_id', 'name']);
        });

        Schema::create('class_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('room')->nullable();
            $table->unsignedSmallInteger('capacity')->default(30);
            $table->foreignId('class_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_class_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_core')->default(true);
            $table->boolean('is_examinable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_section_id', 'subject_id', 'academic_year_id', 'term_id'], 'class_subject_unique');
        });

        Schema::create('timetable_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('timetable_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('room')->nullable();
            $table->timestamps();
            $table->unique(['class_section_id', 'day_of_week', 'timetable_period_id', 'term_id'], 'timetable_class_unique');
            $table->index(['teacher_id', 'day_of_week', 'timetable_period_id', 'term_id'], 'timetable_teacher_index');
        });

        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('curriculum', 30)->default('GES');
            $table->string('name');
            $table->string('code', 30);
            $table->decimal('max_score', 6, 2)->default(100);
            $table->decimal('weight', 5, 2);
            $table->boolean('is_exam')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['level_id', 'curriculum', 'code'], 'component_unique');
        });

        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('curriculum', 30)->default('GES');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('grade_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_scale_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->string('grade', 10);
            $table->string('remark');
            $table->decimal('points', 5, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('report_card_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_templates');
        Schema::dropIfExists('grade_bands');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('timetable_periods');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('class_sections');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
    }
};
