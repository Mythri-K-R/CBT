<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates isolated load-test data that can be torn down without touching
 * real data. All institutions have code prefixed "LT".
 *
 * Usage:
 *   php artisan db:seed --class=LoadTestSeeder
 *   php artisan db:seed --class=LoadTestSeeder --action=cleanup
 *
 * Credentials after seed:
 *   Institution admins : ltadmin01 / LoadTest@123  …  ltadmin20 / LoadTest@123
 *   Student roll nums  : LT01-0001 / DOB 01012000  …  LT20-1000 / DOB 01012000
 *   Test slugs         : lt-test-inst01  …  lt-test-inst20
 */
class LoadTestSeeder extends Seeder
{
    private const INSTITUTIONS          = 20;
    private const STUDENTS_PER_INST     = 1000;
    private const QUESTIONS_PER_TEST    = 10;
    private const TEST_DURATION_MINUTES = 180;
    private const STUDENT_DOB           = '2000-01-01'; // entry format: 01012000

    public function run(): void
    {
        // --action=cleanup  passed via: php artisan db:seed --class=LoadTestSeeder --action=cleanup
        // Artisan doesn't natively pass unknown options into seeder commands, so we
        // read it from the raw CLI argv instead.
        $argv   = $_SERVER['argv'] ?? [];
        $action = 'seed';
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--action=')) {
                $action = substr($arg, strlen('--action='));
            }
        }

        if ($action === 'cleanup') {
            $this->cleanup();
            $this->command->info('Load test data cleaned up.');
            return;
        }

        $this->cleanup();   // always start fresh

        $this->command->info('Seeding load test data...');

        $subjectId = $this->ensureSubject();
        $chapterId = $this->ensureChapter($subjectId);

        $bar = $this->command->getOutput()->createProgressBar(self::INSTITUTIONS);
        $bar->start();

        DB::transaction(function () use ($subjectId, $chapterId, $bar) {
            for ($i = 1; $i <= self::INSTITUTIONS; $i++) {
                $this->createInstitutionBlock($i, $subjectId, $chapterId);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('Done!  Summary:');
        $this->command->table(
            ['Item', 'Count / Value'],
            [
                ['Institutions',   self::INSTITUTIONS],
                ['Students',       self::INSTITUTIONS * self::STUDENTS_PER_INST],
                ['Questions',      self::INSTITUTIONS * self::QUESTIONS_PER_TEST],
                ['Test links',     self::INSTITUTIONS],
                ['Admin password', 'LoadTest@123'],
                ['Student DOB',    '01012000  (DDMMYYYY)'],
                ['Test slugs',     'lt-test-inst01 … lt-test-inst20'],
            ]
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cleanup(): void
    {
        $institutionIds = DB::table('institutions')
            ->where('code', 'like', 'LT%')
            ->pluck('id');

        if ($institutionIds->isEmpty()) return;

        $testIds = DB::table('tests')
            ->whereIn('institution_id', $institutionIds)->pluck('id');

        if ($testIds->isNotEmpty()) {
            $attemptIds = DB::table('test_attempts')
                ->whereIn('test_id', $testIds)->pluck('id');

            if ($attemptIds->isNotEmpty()) {
                DB::table('proctor_events')->whereIn('attempt_id', $attemptIds)->delete();
                DB::table('test_timer_state')->whereIn('attempt_id', $attemptIds)->delete();
                DB::table('test_responses')->whereIn('attempt_id', $attemptIds)->delete();
                DB::table('test_attempts')->whereIn('id', $attemptIds)->delete();
            }

            DB::table('test_links')->whereIn('test_id', $testIds)->delete();
            DB::table('test_questions')->whereIn('test_id', $testIds)->delete();
            DB::table('test_sections')->whereIn('test_id', $testIds)->delete();
            DB::table('tests')->whereIn('id', $testIds)->delete();
        }

        DB::table('students')->whereIn('institution_id', $institutionIds)->delete();
        DB::table('questions')->whereIn('institution_id', $institutionIds)->delete();
        DB::table('users')->whereIn('institution_id', $institutionIds)->delete();
        DB::table('institutions')->whereIn('id', $institutionIds)->delete();
    }

    private function ensureSubject(): int
    {
        $row = DB::table('subjects')
            ->whereNull('institution_id')
            ->where('exam_type', 'neet')
            ->where('name', 'Physics')
            ->first();

        if ($row) return $row->id;

        return DB::table('subjects')->insertGetId([
            'institution_id' => null,
            'exam_type'      => 'neet',
            'name'           => 'Physics',
            'code'           => 'PHY',
            'display_order'  => 1,
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function ensureChapter(int $subjectId): int
    {
        $row = DB::table('chapters')
            ->where('subject_id', $subjectId)->first();

        if ($row) return $row->id;

        return DB::table('chapters')->insertGetId([
            'subject_id'    => $subjectId,
            'name'          => 'Mechanics',
            'code'          => 'PHY-MEC',
            'display_order' => 1,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function createInstitutionBlock(int $n, int $subjectId, int $chapterId): void
    {
        $now  = now();
        $code = sprintf('LT%02d', $n);

        // ── Institution ───────────────────────────────────────────────────────
        $institutionId = DB::table('institutions')->insertGetId([
            'uuid'               => (string) Str::uuid(),
            'code'               => $code,
            'name'               => "Load Test Institute $n",
            'slug'               => "lt-inst-$n",
            'type'               => 'neet_academy',
            'contact_person'     => "LT Admin $n",
            'email'              => "ltinst{$n}@loadtest.local",
            'phone'              => '0000000000',
            'city'               => 'Shivamogga',
            'state'              => 'Karnataka',
            'plan'               => 'enterprise',
            'student_limit'      => 5000,
            'faculty_limit'      => 50,
            'question_limit'     => 100000,
            'subscription_start' => $now->toDateString(),
            'subscription_end'   => $now->copy()->addYear()->toDateString(),
            'is_active'          => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // ── Admin user ────────────────────────────────────────────────────────
        $adminId = DB::table('users')->insertGetId([
            'uuid'                  => (string) Str::uuid(),
            'institution_id'        => $institutionId,
            'role'                  => 'institution_admin',
            'name'                  => "LT Admin $n",
            'email'                 => "ltadmin{$n}@loadtest.local",
            'username'              => sprintf('ltadmin%02d', $n),
            'password'              => Hash::make('LoadTest@123'),
            'email_verified_at'     => $now,
            'is_active'             => 1,
            'force_password_change' => 0,
            'can_create_questions'  => 1,
            'can_edit_questions'    => 1,
            'can_create_tests'      => 1,
            'can_schedule_tests'    => 1,
            'can_view_results'      => 1,
            'can_view_analytics'    => 1,
            'can_generate_links'    => 1,
            'can_manage_students'   => 1,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        // ── Questions ─────────────────────────────────────────────────────────
        $optionsJson = json_encode([
            ['key' => 'A', 'text' => '9 J',         'image' => null],
            ['key' => 'B', 'text' => '12 J',        'image' => null],
            ['key' => 'C', 'text' => '6 J',         'image' => null],
            ['key' => 'D', 'text' => '18 J',        'image' => null],
        ]);

        $questionIds = [];
        for ($q = 1; $q <= self::QUESTIONS_PER_TEST; $q++) {
            $questionIds[] = DB::table('questions')->insertGetId([
                'uuid'            => (string) Str::uuid(),
                'institution_id'  => $institutionId,
                'created_by'      => $adminId,
                'exam_type'       => 'neet',
                'subject_id'      => $subjectId,
                'chapter_id'      => $chapterId,
                'topic_id'        => null,
                'type'            => 'single_mcq',
                'difficulty'      => 'medium',
                'question_text'   => "LT-Q{$q} [Inst {$n}]: A 2 kg body moves at 3 m/s. Its kinetic energy is?",
                'options'         => $optionsJson,
                'correct_answer'  => 'A',
                'positive_marks'  => 4.00,
                'negative_marks'  => 1.00,
                'language'        => 'english',
                'source_type'     => 'manual',
                'ai_review_status'=> 'not_applicable',
                'has_latex'       => 0,
                'has_image'       => 0,
                'times_used'      => 0,
                'times_correct'   => 0,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // ── Test ──────────────────────────────────────────────────────────────
        $testId = DB::table('tests')->insertGetId([
            'uuid'                     => (string) Str::uuid(),
            'institution_id'           => $institutionId,
            'created_by'               => $adminId,
            'template_id'              => null,
            'title'                    => "Load Test Exam — Institute $n",
            'exam_type'                => 'neet',
            'test_category'            => 'mock_test',
            'duration_minutes'         => self::TEST_DURATION_MINUTES,
            'has_sectional_timing'     => 0,
            'allow_section_switching'  => 1,
            'shuffle_questions'        => 0,
            'shuffle_options'          => 0,
            'show_result_immediately'  => 1,
            'show_solutions_after'     => 1,
            'max_attempts'             => 1,
            'fullscreen_required'      => 0,   // disabled to simplify k6 flows
            'tab_switch_limit'         => 999,
            'disable_copy_paste'       => 0,
            'disable_right_click'      => 0,
            'auto_submit_on_violation' => 0,
            'status'                   => 'live',
            'total_questions'          => self::QUESTIONS_PER_TEST,
            'total_marks'              => self::QUESTIONS_PER_TEST * 4,
            'total_assigned_students'  => 0,
            'total_attempts'           => 0,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        // ── Test section ──────────────────────────────────────────────────────
        $sectionId = DB::table('test_sections')->insertGetId([
            'test_id'        => $testId,
            'name'           => 'Physics',
            'subject_id'     => $subjectId,
            'question_count' => self::QUESTIONS_PER_TEST,
            'positive_marks' => 4.00,
            'negative_marks' => 1.00,
            'display_order'  => 0,
        ]);

        // ── Test questions ────────────────────────────────────────────────────
        $tqRows = [];
        foreach ($questionIds as $qNum => $qId) {
            $tqRows[] = [
                'test_id'         => $testId,
                'section_id'      => $sectionId,
                'question_id'     => $qId,
                'question_number' => $qNum + 1,
                'positive_marks'  => 4.00,
                'negative_marks'  => 1.00,
                'is_mandatory'    => 1,
            ];
        }
        DB::table('test_questions')->insert($tqRows);

        // ── Test link ─────────────────────────────────────────────────────────
        DB::table('test_links')->insert([
            'test_id'        => $testId,
            'institution_id' => $institutionId,
            'slug'           => sprintf('lt-test-inst%02d', $n),
            'is_active'      => 1,
            'expires_at'     => $now->copy()->addDays(7),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // ── Students (bulk, 500 per chunk) ────────────────────────────────────
        $studentRows = [];
        for ($s = 1; $s <= self::STUDENTS_PER_INST; $s++) {
            $studentRows[] = [
                'institution_id' => $institutionId,
                'name'           => sprintf('LT Student %02d-%04d', $n, $s),
                'roll_number'    => sprintf('LT%02d-%04d', $n, $s),
                'date_of_birth'  => self::STUDENT_DOB,
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        foreach (array_chunk($studentRows, 500) as $chunk) {
            DB::table('students')->insert($chunk);
        }
    }
}
