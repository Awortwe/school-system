<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('session', 20)->default('morning');
            $table->string('status', 20)->default('present');
            $table->string('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'date', 'session'], 'attendance_student_date_session_unique');
            $table->index(['class_section_id', 'date']);
        });

        Schema::create('result_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamps();
            $table->unique(['class_section_id', 'subject_id', 'term_id'], 'result_sheet_unique');
        });

        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('is_exempt')->default(false);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['result_sheet_id', 'student_id', 'assessment_component_id'], 'score_unique');
        });

        Schema::create('term_subject_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_score', 6, 2)->nullable();
            $table->string('grade', 10)->nullable();
            $table->string('remark')->nullable();
            $table->decimal('points', 5, 2)->nullable();
            $table->boolean('is_complete')->default(false);
            $table->text('teacher_comment')->nullable();
            $table->timestamps();
            $table->unique(['result_sheet_id', 'student_id']);
        });

        Schema::create('result_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('class_section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('conduct_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('conduct')->nullable();
            $table->string('attitude')->nullable();
            $table->string('interest')->nullable();
            $table->text('class_teacher_comment')->nullable();
            $table->text('headteacher_comment')->nullable();
            $table->decimal('height', 5, 1)->nullable();
            $table->decimal('weight', 5, 1)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'term_id']);
        });

        Schema::create('report_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('published');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['class_section_id', 'term_id']);
        });

        Schema::create('published_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_section_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('payload');
            $table->string('pdf_path')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'term_id', 'version'], 'published_report_version_unique');
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('min_average', 6, 2)->default(50);
            $table->decimal('min_pass_subjects_percent', 5, 2)->default(50);
            $table->unsignedSmallInteger('max_core_failures')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promotion_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->foreignId('to_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->string('decision', 20);
            $table->decimal('average', 6, 2)->nullable();
            $table->unsignedSmallInteger('core_failures')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_records');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('published_reports');
        Schema::dropIfExists('report_publications');
        Schema::dropIfExists('conduct_assessments');
        Schema::dropIfExists('result_events');
        Schema::dropIfExists('term_subject_results');
        Schema::dropIfExists('assessment_scores');
        Schema::dropIfExists('result_sheets');
        Schema::dropIfExists('attendance_records');
    }
};
