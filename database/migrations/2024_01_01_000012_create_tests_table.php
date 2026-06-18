<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->references('id')->on('users');
            $table->foreignId('template_id')->nullable()->constrained('exam_templates');
            // Info
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet']);
            $table->enum('test_category', ['chapter_test','subject_test','weekly_test','mock_test','grand_test','practice_test','custom'])->default('chapter_test');
            // Timing
            $table->unsignedInteger('duration_minutes');
            $table->boolean('has_sectional_timing')->default(false);
            $table->boolean('allow_section_switching')->default(true);
            // Schedule
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('result_publish_at')->nullable();
            // Question settings
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('show_result_immediately')->default(true);
            $table->boolean('show_solutions_after')->default(true);
            $table->unsignedInteger('max_attempts')->default(1);
            // Anti-cheat
            $table->boolean('fullscreen_required')->default(true);
            $table->unsignedInteger('tab_switch_limit')->default(3);
            $table->boolean('disable_copy_paste')->default(true);
            $table->boolean('disable_right_click')->default(true);
            $table->boolean('auto_submit_on_violation')->default(false);
            // Instructions
            $table->text('instructions')->nullable();
            // Status
            $table->enum('status', ['draft','scheduled','live','completed','cancelled','archived'])->default('draft');
            // Denormalized stats
            $table->unsignedInteger('total_questions')->default(0);
            $table->decimal('total_marks', 7, 2)->default(0);
            $table->unsignedInteger('total_assigned_students')->default(0);
            $table->unsignedInteger('total_attempts')->default(0);
            $table->decimal('avg_score', 5, 2)->nullable();
            $table->decimal('avg_accuracy', 5, 2)->nullable();
            $table->decimal('highest_score', 7, 2)->nullable();
            $table->decimal('lowest_score', 7, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('institution_id');
            $table->index('status');
            $table->index('exam_type');
            $table->index('test_category');
            $table->index(['scheduled_start', 'scheduled_end']);
        });

        Schema::create('test_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('subject_id')->nullable()->constrained();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('question_count');
            $table->unsignedInteger('mandatory_count')->nullable();
            $table->decimal('positive_marks', 5, 2)->default(4.00);
            $table->decimal('negative_marks', 5, 2)->default(1.00);
            $table->decimal('partial_marks', 5, 2)->nullable();
            $table->unsignedInteger('display_order')->default(0);

            $table->index('test_id');
        });

        Schema::create('test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('test_sections')->nullOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->unsignedInteger('question_number');
            $table->decimal('positive_marks', 5, 2);
            $table->decimal('negative_marks', 5, 2);
            $table->decimal('partial_marks', 5, 2)->nullable();
            $table->boolean('is_mandatory')->default(true);

            $table->unique(['test_id', 'question_id']);
            $table->index('section_id');
        });

        Schema::create('test_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();

            $table->unique(['test_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_batches');
        Schema::dropIfExists('test_questions');
        Schema::dropIfExists('test_sections');
        Schema::dropIfExists('tests');
    }
};
