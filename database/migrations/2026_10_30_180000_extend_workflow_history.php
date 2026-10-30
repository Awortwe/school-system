<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('result_sheets', fn (Blueprint $table) => $table->json('configuration')->nullable());
        Schema::table('assessment_components', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::table('payments', fn (Blueprint $table) => $table->json('receipt_snapshot')->nullable());
        Schema::table('import_batches', function (Blueprint $table) { $table->string('path')->nullable(); $table->foreignId('class_section_id')->nullable()->constrained(); });
        Schema::create('payment_claims', function (Blueprint $table) { $table->string('key', 150)->primary(); $table->timestamp('created_at'); });
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained('users'); $table->foreignId('class_section_id')->constrained(); $table->foreignId('term_id')->constrained();
            $table->string('status')->default('pending'); $table->string('path')->nullable(); $table->text('error')->nullable(); $table->timestamps();
        });
        Schema::create('learning_observations', function (Blueprint $table) {
            $table->id(); $table->foreignId('student_id')->constrained(); $table->foreignId('term_id')->constrained(); $table->string('area'); $table->string('skill'); $table->string('progress'); $table->text('notes')->nullable(); $table->foreignId('recorded_by')->constrained('users'); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('learning_observations'); Schema::dropIfExists('report_jobs');
        Schema::dropIfExists('payment_claims');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('receipt_snapshot'));
        Schema::table('import_batches', function (Blueprint $table) { $table->dropConstrainedForeignId('class_section_id'); $table->dropColumn('path'); });
        Schema::table('assessment_components', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('result_sheets', fn (Blueprint $table) => $table->dropColumn('configuration'));
    }
};
