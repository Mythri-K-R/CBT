<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subject_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet']);
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('total_correct')->default(0);
            $table->unsignedInteger('total_wrong')->default(0);
            $table->decimal('accuracy', 5, 2)->default(0);
            $table->unsignedInteger('avg_time_per_question')->default(0);
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['student_id', 'subject_id']);
        });

        Schema::create('student_chapter_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained();
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('total_correct')->default(0);
            $table->unsignedInteger('total_wrong')->default(0);
            $table->decimal('accuracy', 5, 2)->default(0);
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['student_id', 'chapter_id']);
        });

        Schema::create('batch_test_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_assigned')->default(0);
            $table->unsignedInteger('total_attempted')->default(0);
            $table->decimal('avg_score', 7, 2)->default(0);
            $table->decimal('avg_accuracy', 5, 2)->default(0);
            $table->decimal('highest_score', 7, 2)->default(0);
            $table->decimal('lowest_score', 7, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['batch_id', 'test_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_test_stats');
        Schema::dropIfExists('student_chapter_stats');
        Schema::dropIfExists('student_subject_stats');
    }
};
