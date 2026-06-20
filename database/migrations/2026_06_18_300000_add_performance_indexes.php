<?php

/**
 * Performance indexes migration.
 *
 * WHY: At 20,000+ concurrent students, queries like "find all in-progress
 * attempts whose time expired" or "filter active students by institution"
 * must use composite indexes for sub-millisecond lookups — single-column
 * indexes leave MySQL doing partial scans under heavy load.
 *
 * All index names are explicit so this migration is safe to re-run on
 * schemas where some indexes already exist (it checks before adding).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── test_attempts ──────────────────────────────────────────────────
        // AutoSubmitExpiredTests: WHERE status='in_progress' AND server_end_time < NOW()
        Schema::table('test_attempts', function (Blueprint $table) {
            if (!$this->indexExists('test_attempts', 'ta_status_end')) {
                $table->index(['status', 'server_end_time'], 'ta_status_end');
            }
            // Result views: WHERE test_id=? AND status IN('submitted','completed')
            if (!$this->indexExists('test_attempts', 'ta_test_status')) {
                $table->index(['test_id', 'status'], 'ta_test_status');
            }
        });

        // ── test_responses ─────────────────────────────────────────────────
        // Submission scoring: WHERE attempt_id=? (needs status for palette counts)
        Schema::table('test_responses', function (Blueprint $table) {
            if (!$this->indexExists('test_responses', 'tr_attempt_status')) {
                $table->index(['attempt_id', 'status'], 'tr_attempt_status');
            }
        });

        // ── tests ──────────────────────────────────────────────────────────
        // Institution dashboard: WHERE institution_id=? AND status='live'
        Schema::table('tests', function (Blueprint $table) {
            if (!$this->indexExists('tests', 'tests_inst_status')) {
                $table->index(['institution_id', 'status'], 'tests_inst_status');
            }
            // ActivateScheduledTests: WHERE status='scheduled' AND scheduled_start<=NOW()
            if (!$this->indexExists('tests', 'tests_status_sched')) {
                $table->index(['status', 'scheduled_start'], 'tests_status_sched');
            }
            // CompleteEndedTests: WHERE status='live' AND scheduled_end<=NOW()
            if (!$this->indexExists('tests', 'tests_status_end')) {
                $table->index(['status', 'scheduled_end'], 'tests_status_end');
            }
        });

        // ── test_links ─────────────────────────────────────────────────────
        // Link management view: WHERE test_id=? AND is_active=1
        Schema::table('test_links', function (Blueprint $table) {
            if (!$this->indexExists('test_links', 'tl_test_active')) {
                $table->index(['test_id', 'is_active'], 'tl_test_active');
            }
        });

        // ── students ───────────────────────────────────────────────────────
        // Student lists: WHERE institution_id=? AND is_active=1
        Schema::table('students', function (Blueprint $table) {
            if (!$this->indexExists('students', 'stud_inst_active')) {
                $table->index(['institution_id', 'is_active'], 'stud_inst_active');
            }
        });

        // ── questions ──────────────────────────────────────────────────────
        // Question bank filters: WHERE institution_id=? AND subject_id=? AND difficulty=?
        Schema::table('questions', function (Blueprint $table) {
            if (!$this->indexExists('questions', 'q_inst_subj_diff')) {
                $table->index(['institution_id', 'subject_id', 'difficulty'], 'q_inst_subj_diff');
            }
            // Auto-pick questions: WHERE institution_id=? AND exam_type=? AND status='active'
            if (!$this->indexExists('questions', 'q_inst_type_status')) {
                $table->index(['institution_id', 'exam_type', 'status'], 'q_inst_type_status');
            }
        });

        // ── audit_logs ─────────────────────────────────────────────────────
        // Paginated audit log: WHERE institution_id=? ORDER BY created_at DESC
        Schema::table('audit_logs', function (Blueprint $table) {
            if (!$this->indexExists('audit_logs', 'al_inst_created')) {
                $table->index(['institution_id', 'created_at'], 'al_inst_created');
            }
        });

        // ── student_subject_stats ──────────────────────────────────────────
        // Analytics lookups: WHERE student_id=? AND subject_id=?
        Schema::table('student_subject_stats', function (Blueprint $table) {
            if (!$this->indexExists('student_subject_stats', 'sss_stud_subj')) {
                $table->index(['student_id', 'subject_id'], 'sss_stud_subj');
            }
        });

        // ── student_chapter_stats ─────────────────────────────────────────
        // Analytics lookups: WHERE student_id=? AND chapter_id=?
        Schema::table('student_chapter_stats', function (Blueprint $table) {
            if (!$this->indexExists('student_chapter_stats', 'scs_stud_chap')) {
                $table->index(['student_id', 'chapter_id'], 'scs_stud_chap');
            }
        });

        // ── batch_students ─────────────────────────────────────────────────
        // Active batch member counts: WHERE batch_id=? AND status='active'
        Schema::table('batch_students', function (Blueprint $table) {
            if (!$this->indexExists('batch_students', 'bs_batch_status')) {
                $table->index(['batch_id', 'status'], 'bs_batch_status');
            }
        });

        // ── notifications ──────────────────────────────────────────────────
        // Unread notification badge: WHERE institution_id=? AND is_read=0
        Schema::table('notifications', function (Blueprint $table) {
            if (!$this->indexExists('notifications', 'notif_inst_read')) {
                $table->index(['institution_id', 'is_read'], 'notif_inst_read');
            }
        });

        // ── test_link_clicks ───────────────────────────────────────────────
        // Click analytics: WHERE link_id=? ORDER BY created_at
        Schema::table('test_link_clicks', function (Blueprint $table) {
            if (!$this->indexExists('test_link_clicks', 'tlc_link_created')) {
                $table->index(['link_id', 'created_at'], 'tlc_link_created');
            }
        });

        // ── proctor_events ─────────────────────────────────────────────────
        // Proctor panel view: WHERE attempt_id=? AND event_type=?
        Schema::table('proctor_events', function (Blueprint $table) {
            if (!$this->indexExists('proctor_events', 'pe_attempt_type')) {
                $table->index(['attempt_id', 'event_type'], 'pe_attempt_type');
            }
        });

        // ── batch_test_stats ───────────────────────────────────────────────
        // Batch analytics: WHERE batch_id=? AND test_id=?
        Schema::table('batch_test_stats', function (Blueprint $table) {
            if (!$this->indexExists('batch_test_stats', 'bts_batch_test')) {
                $table->index(['batch_id', 'test_id'], 'bts_batch_test');
            }
        });
    }

    public function down(): void
    {
        $drops = [
            'test_attempts'        => ['ta_status_end', 'ta_test_status'],
            'test_responses'       => ['tr_attempt_status'],
            'tests'                => ['tests_inst_status', 'tests_status_sched', 'tests_status_end'],
            'test_links'           => ['tl_test_active'],
            'students'             => ['stud_inst_active'],
            'questions'            => ['q_inst_subj_diff', 'q_inst_type_status'],
            'audit_logs'           => ['al_inst_created'],
            'student_subject_stats'=> ['sss_stud_subj'],
            'student_chapter_stats'=> ['scs_stud_chap'],
            'batch_students'       => ['bs_batch_status'],
            'notifications'        => ['notif_inst_read'],
            'test_link_clicks'     => ['tlc_link_created'],
            'proctor_events'       => ['pe_attempt_type'],
            'batch_test_stats'     => ['bts_batch_test'],
        ];

        foreach ($drops as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $idx) {
                    $blueprint->dropIndex($idx);
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
