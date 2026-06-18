<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->references('id')->on('users');
            // Classification
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet']);
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('chapter_id')->constrained();
            $table->foreignId('topic_id')->nullable()->constrained();
            $table->enum('difficulty', ['easy','medium','hard'])->default('medium');
            // Content
            $table->enum('type', ['single_mcq','multiple_mcq','numerical','integer','assertion_reason','match_following'])->default('single_mcq');
            $table->text('question_text');
            $table->string('question_image', 500)->nullable();
            $table->json('options');
            // Answer
            $table->string('correct_answer', 100);
            $table->decimal('answer_tolerance', 10, 4)->nullable();
            // Marks
            $table->decimal('positive_marks', 5, 2)->default(4.00);
            $table->decimal('negative_marks', 5, 2)->default(1.00);
            $table->decimal('partial_marks', 5, 2)->nullable();
            // Metadata
            $table->text('explanation')->nullable();
            $table->string('explanation_image', 500)->nullable();
            $table->string('source')->nullable();
            $table->json('tags')->nullable();
            $table->enum('language', ['english','hindi','kannada'])->default('english');
            $table->boolean('has_latex')->default(false);
            $table->boolean('has_image')->default(false);
            // Source tracking
            $table->enum('source_type', ['platform','manual','excel_import','doc_import','ai_generated','cloned'])->default('manual');
            $table->foreignId('original_question_id')->nullable()->references('id')->on('questions');
            // AI review
            $table->enum('ai_review_status', ['not_applicable','pending_review','approved','rejected'])->default('not_applicable');
            $table->foreignId('ai_reviewed_by')->nullable()->references('id')->on('users');
            // Usage stats
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->unsignedInteger('times_wrong')->default(0);
            $table->unsignedInteger('times_skipped')->default(0);
            $table->unsignedInteger('avg_time_seconds')->default(0);
            // Status
            $table->enum('status', ['draft','active','archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('institution_id');
            $table->index('exam_type');
            $table->index('subject_id');
            $table->index('chapter_id');
            $table->index('topic_id');
            $table->index('difficulty');
            $table->index('source_type');
            $table->fullText('question_text');
        });

        Schema::create('question_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->enum('image_type', ['question','option_a','option_b','option_c','option_d','explanation']);
            $table->string('file_path', 500);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('question_id');
        });

        Schema::create('question_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->references('id')->on('users');
            $table->enum('import_type', ['csv','excel','json','pdf']);
            $table->string('file_path', 500);
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet']);
            $table->foreignId('subject_id')->nullable()->constrained();
            $table->foreignId('chapter_id')->nullable()->constrained();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('duplicate_skipped')->default(0);
            $table->json('error_log')->nullable();
            $table->enum('status', ['pending','processing','review','completed','failed'])->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_imports');
        Schema::dropIfExists('question_images');
        Schema::dropIfExists('questions');
    }
};
