<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('admission_no')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('category', 20)->default('day');
            $table->string('status', 20)->default('active');
            $table->date('admission_date')->nullable();
            $table->foreignId('current_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('nationality')->nullable();
            $table->string('religion')->nullable();
            $table->text('medical_notes')->nullable();
            $table->string('previous_school')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'current_section_id']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 30);
            $table->string('alt_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->string('national_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['last_name', 'phone']);
        });

        Schema::create('student_guardian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 30)->default('parent');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_emergency')->default(false);
            $table->string('custody', 30)->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'guardian_id']);
        });

        Schema::create('sibling_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('sibling_group_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sibling_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unique(['sibling_group_id', 'student_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('enrolled_on');
            $table->date('left_on')->nullable();
            $table->foreignId('promoted_from_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'academic_year_id']);
        });

        Schema::create('student_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->unique();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('guardian_name');
            $table->string('guardian_phone', 30);
            $table->string('guardian_email')->nullable();
            $table->string('guardian_relationship', 30)->default('parent');
            $table->text('address')->nullable();
            $table->string('previous_school')->nullable();
            $table->text('assessment_notes')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->text('decision_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('converted_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->default('students');
            $table->string('file_name');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 30)->default('phone');
            $table->string('direction', 20)->default('outbound');
            $table->string('subject')->nullable();
            $table->text('summary');
            $table->timestamp('occurred_at');
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('student_status_history');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('sibling_group_student');
        Schema::dropIfExists('sibling_groups');
        Schema::dropIfExists('student_guardian');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
    }
};
