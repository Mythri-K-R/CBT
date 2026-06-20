<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet']);
            $table->unsignedInteger('total_duration_minutes');
            $table->unsignedInteger('total_questions');
            $table->decimal('total_marks', 7, 2);
            $table->boolean('has_sectional_timing')->default(false);
            $table->boolean('allow_section_switching')->default(true);
            $table->json('section_config')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exam_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('exam_templates')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('subject_id')->nullable()->constrained();
            $table->unsignedInteger('question_count');
            $table->unsignedInteger('mandatory_count')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('positive_marks', 5, 2)->default(4.00);
            $table->decimal('negative_marks', 5, 2)->default(1.00);
            $table->decimal('partial_marks', 5, 2)->nullable();
            $table->enum('question_type', ['single_mcq','multiple_mcq','numerical','integer','assertion_reason','mixed'])->default('single_mcq');
            $table->unsignedInteger('display_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_template_sections');
        Schema::dropIfExists('exam_templates');
    }
};
