<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('test_id')->constrained();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('link_id')->nullable()->constrained('test_links');
            // Server-side timing
            $table->timestamp('started_at');
            $table->timestamp('server_end_time');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            // Submission
            $table->enum('submission_type', ['manual','auto_timeout','auto_violation','force_submit'])->nullable();
            // Anti-cheat
            $table->unsignedInteger('tab_switch_count')->default(0);
            $table->unsignedInteger('fullscreen_violations')->default(0);
            $table->unsignedInteger('screen_capture_attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('device_info')->nullable();
            $table->string('active_session_id')->nullable();
            // Results
            $table->decimal('total_score', 7, 2)->nullable();
            $table->decimal('max_possible_score', 7, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->unsignedInteger('total_correct')->nullable();
            $table->unsignedInteger('total_wrong')->nullable();
            $table->unsignedInteger('total_unattempted')->nullable();
            $table->unsignedInteger('total_marked_review')->nullable();
            $table->decimal('accuracy', 5, 2)->nullable();
            // Rank
            $table->unsignedInteger('rank_in_test')->nullable();
            $table->unsignedInteger('total_participants')->nullable();
            $table->decimal('percentile', 5, 2)->nullable();
            // Subject breakdown
            $table->json('subject_scores')->nullable();
            // Status
            $table->enum('status', ['in_progress','submitted','completed','abandoned'])->default('in_progress');
            $table->timestamps();

            $table->unique(['test_id', 'student_id']);
            $table->index('student_id');
            $table->index('status');
            $table->index(['test_id', 'total_score']);
        });

        Schema::create('test_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('test_attempts')->cascadeOnDelete();
            $table->foreignId('test_question_id')->constrained('test_questions');
            $table->foreignId('question_id')->constrained();
            $table->foreignId('section_id')->nullable()->constrained('test_sections');
            // Response
            $table->string('selected_answer', 255)->nullable();
            // Palette status
            $table->enum('status', ['not_visited','not_answered','answered','marked_review','answered_marked_review'])->default('not_visited');
            // Evaluation
            $table->boolean('is_correct')->nullable();
            $table->decimal('marks_awarded', 5, 2)->nullable();
            // Time tracking
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamp('first_visited_at')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->unsignedInteger('visit_count')->default(0);
            $table->json('answer_changes')->nullable();

            $table->unique(['attempt_id', 'test_question_id']);
            $table->index('question_id');
        });

        Schema::create('test_timer_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->unique()->constrained('test_attempts')->cascadeOnDelete();
            $table->unsignedInteger('remaining_seconds');
            $table->json('section_timers')->nullable();
            $table->timestamp('last_sync_at');
        });

        Schema::create('proctor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('test_attempts')->cascadeOnDelete();
            $table->enum('event_type', [
                'tab_switch','fullscreen_exit','fullscreen_enter',
                'copy_attempt','right_click','window_resize',
                'idle_detected','screenshot_attempt','session_conflict'
            ]);
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('attempt_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proctor_events');
        Schema::dropIfExists('test_timer_state');
        Schema::dropIfExists('test_responses');
        Schema::dropIfExists('test_attempts');
    }
};
