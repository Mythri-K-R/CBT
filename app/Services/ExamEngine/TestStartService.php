<?php

namespace App\Services\ExamEngine;

use App\Events\ExamStarted;
use App\Events\TestStarted;
use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\TestLink;
use App\Models\TestResponse;
use App\Models\TestTimerState;
use App\Services\ExamEngine\ExamStateCacheService;
use App\Services\Infrastructure\DistributedLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TestStartService
{
    public function __construct(
        private readonly DistributedLockService  $locks,
        private readonly ExamStateCacheService   $stateCache
    ) {}

    public function start(TestLink $link, int $studentId, string $ip, string $userAgent): array
    {
        $test    = $link->test;
        $student = Student::where('id', $studentId)
                          ->where('institution_id', $link->institution_id)
                          ->where('is_active', true)
                          ->first();

        if (!$student) {
            return ['success' => false, 'message' => 'Student not found.'];
        }

        // Check if test is accessible
        if (!in_array($test->status, ['live', 'scheduled'])) {
            return ['success' => false, 'message' => 'This test is not currently active.'];
        }

        // ── Fast path: resume existing in-progress attempt (no lock needed) ──
        // This is a read-only check; no mutation happens here.
        $existingAttempt = TestAttempt::where('test_id', $test->id)
                                       ->where('student_id', $studentId)
                                       ->first();

        if ($existingAttempt) {
            if ($existingAttempt->status === 'in_progress' && !$existingAttempt->isExpired()) {
                // Resume the attempt
                return [
                    'success'       => true,
                    'attempt'       => $existingAttempt,
                    'session_token' => $this->refreshSessionToken($existingAttempt),
                    'questions'     => $this->loadQuestions($existingAttempt),
                    'resumed'       => true,
                ];
            }

            if ($existingAttempt->status !== 'in_progress') {
                return ['success' => false, 'message' => 'You have already submitted this test.'];
            }
        }

        // ── Distributed lock before creating a new attempt ───────────────────
        // Race condition without lock:
        //   Student opens exam on phone AND laptop at the same instant.
        //   Both requests pass the check above (no existing attempt found).
        //   Both reach TestAttempt::create() simultaneously.
        //   The second insert crashes on unique(test_id, student_id).
        //
        // With lock:
        //   First device acquires lock, creates attempt, releases lock.
        //   Second device acquires lock, re-reads inside callback, finds the
        //   attempt just created by the first device, and returns a resume response.
        $lockKey = $this->locks->examStartKey($test->id, $studentId);

        return $this->locks->withLock(
            key:         $lockKey,
            ttl:         DistributedLockService::TTL_START,
            waitSeconds: 10,
            callback:    function () use ($test, $student, $link, $ip, $userAgent, $studentId): array {
                // Double-checked locking: re-read inside the lock.
                // The device that lost the lock race now finds the attempt created
                // by the winning device and resumes gracefully.
                $existing = TestAttempt::where('test_id', $test->id)
                                        ->where('student_id', $studentId)
                                        ->first();

                if ($existing) {
                    if ($existing->status === 'in_progress' && !$existing->isExpired()) {
                        return [
                            'success'       => true,
                            'attempt'       => $existing,
                            'session_token' => $this->refreshSessionToken($existing),
                            'questions'     => $this->loadQuestions($existing),
                            'resumed'       => true,
                        ];
                    }

                    if ($existing->status !== 'in_progress') {
                        return ['success' => false, 'message' => 'You have already submitted this test.'];
                    }
                }

                // ── DB transaction: exact Phase 1 logic — unchanged ───────────
                $result = DB::transaction(function () use ($test, $student, $link, $ip, $userAgent) {
                    $startedAt   = now();
                    $serverEndAt = $startedAt->copy()->addMinutes($test->duration_minutes);

                    $attempt = TestAttempt::create([
                        'test_id'         => $test->id,
                        'student_id'      => $student->id,
                        'link_id'         => $link->id,
                        'started_at'      => $startedAt,
                        'server_end_time' => $serverEndAt,
                        'ip_address'      => $ip,
                        'device_info'     => $userAgent,
                        'status'          => 'in_progress',
                    ]);

                    // Pre-create all response rows so saves are simple updates, not inserts
                    $questions  = $test->testQuestions()->with('question')->get();
                    $insertRows = [];

                    foreach ($questions as $tq) {
                        $insertRows[] = [
                            'attempt_id'         => $attempt->id,
                            'test_question_id'   => $tq->id,
                            'question_id'        => $tq->question_id,
                            'section_id'         => $tq->section_id,
                            'status'             => 'not_visited',
                            'time_spent_seconds' => 0,
                            'visit_count'        => 0,
                        ];
                    }

                    TestResponse::insert($insertRows);

                    // Create server-side timer
                    $sectionTimers = [];
                    if ($test->has_sectional_timing) {
                        foreach ($test->sections as $section) {
                            if ($section->duration_minutes) {
                                $sectionTimers[$section->id] = $section->duration_minutes * 60;
                            }
                        }
                    }

                    TestTimerState::create([
                        'attempt_id'        => $attempt->id,
                        'remaining_seconds' => $test->duration_minutes * 60,
                        'section_timers'    => $sectionTimers ?: null,
                        'last_sync_at'      => now(),
                    ]);

                    $sessionToken = $this->refreshSessionToken($attempt);

                    return [
                        'success'       => true,
                        'attempt'       => $attempt,
                        'session_token' => $sessionToken,
                        'questions'     => $this->loadQuestions($attempt),
                    ];
                });

                // Warmup exam state cache after the transaction commits.
                // Failures are non-fatal: the first timer sync falls back to DB.
                if ($result['success'] ?? false) {
                    $this->stateCache->warmup($result['attempt']);

                    $isResumed = $result['resumed'] ?? false;

                    // Fire new ExamStarted event (Feature 4).
                    event(new ExamStarted($result['attempt'], $isResumed));

                    // Fire legacy TestStarted — was registered but never emitted before.
                    // Kept for backward compatibility with LogAuditTrail listener.
                    if (!$isResumed) {
                        event(new TestStarted($result['attempt']));
                    }
                }

                return $result;
            },
            fallback: ['success' => false, 'message' => 'Exam start is in progress. Please refresh and try again.']
        );
    }

    private function refreshSessionToken(TestAttempt $attempt): string
    {
        $token = Str::random(64);
        $ttl   = config('examsphere.exam.session_token_ttl_minutes', 5) * 60;
        // Store on Redis DB0 (same connection as ExamStateCacheService) so that
        // `php artisan cache:clear` (which flushes Cache DB1) cannot wipe active
        // exam session tokens mid-exam.
        Redis::connection('default')->setex("exam:session:{$attempt->id}", $ttl, $token);
        $attempt->update(['active_session_id' => $token]);
        return $token;
    }

    private function loadQuestions(TestAttempt $attempt): array
    {
        $test      = $attempt->test;
        $responses = $attempt->responses()->get()->keyBy('test_question_id');

        $testQuestions = $test->testQuestions()
            ->with('question:id,uuid,type,question_text,question_image,options,has_latex,has_image,positive_marks,negative_marks')
            ->with('section:id,name')
            ->orderBy('question_number')
            ->get();

        if ($test->shuffle_questions) {
            $testQuestions = $testQuestions->shuffle();
        }

        return $testQuestions->map(function ($tq) use ($responses, $test) {
            $response = $responses[$tq->id] ?? null;
            $q        = $tq->question;

            $options = $q->options;
            if ($test->shuffle_options && is_array($options) && count($options) > 0) {
                shuffle($options);
            }

            return [
                'test_question_id' => $tq->id,
                'question_number'  => $tq->question_number,
                'section_id'       => $tq->section_id,
                'section_name'     => optional($tq->section)->name,
                'positive_marks'   => $tq->positive_marks,
                'negative_marks'   => $tq->negative_marks,
                'type'             => $q->type,
                'question_text'    => $q->question_text,
                'question_image'   => $q->question_image,
                'options'          => $options,
                'has_latex'        => $q->has_latex,
                // Current response state
                'selected_answer'  => $response?->selected_answer,
                'status'           => $response?->status ?? 'not_visited',
                'time_spent'       => $response?->time_spent_seconds ?? 0,
            ];
        })->values()->toArray();
    }
}
