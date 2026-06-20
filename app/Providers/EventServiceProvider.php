<?php

namespace App\Providers;

// ── Existing events (unchanged — backward compatibility) ──────────────────────
use App\Events\TestStarted;
use App\Events\TestSubmitted;
use App\Events\QuestionsImported;
use App\Events\StudentCreated;
use App\Events\SubscriptionExpiring;

// ── New Feature 4 events ──────────────────────────────────────────────────────
use App\Events\ExamStarted;
use App\Events\AnswerSaved;
use App\Events\AutoSaveCompleted;
use App\Events\OfflineAnswersSynced;
use App\Events\ExamSubmitted;
use App\Events\ExamAutoSubmitted;
use App\Events\ExamExpired;
use App\Events\ResultCalculated;
use App\Events\ResultPublished;
use App\Events\RankGenerated;
use App\Events\StudentLoggedIn;
use App\Events\StudentLoggedOut;
use App\Events\InstitutionUserLoggedIn;
use App\Events\SessionConflictDetected;
use App\Events\SuspiciousActivityDetected;
use App\Events\TabSwitchDetected;
use App\Events\FullscreenExitDetected;
use App\Events\ExamHeartbeatReceived;

// ── Existing listeners (unchanged) ────────────────────────────────────────────
use App\Listeners\EvaluateTestAttempt;
use App\Listeners\UpdateTestDenormalizedCounts;
use App\Listeners\LogAuditTrail;
use App\Listeners\SendExpiryNotification;

// ── New Feature 4 listeners ───────────────────────────────────────────────────
use App\Listeners\LogExamActivity;
use App\Listeners\LogSecurityEvent;
use App\Listeners\NotifyResultReady;
use App\Listeners\NotifyAutoSubmit;

// ── New Feature 5 listeners ───────────────────────────────────────────────────
use App\Listeners\UpdateMonitoringState;
use App\Listeners\UpdateHeartbeatStatus;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // ── EXISTING EVENTS — unchanged for backward compatibility ────────────
        TestStarted::class => [
            LogAuditTrail::class,           // sync: audit log
        ],
        TestSubmitted::class => [
            EvaluateTestAttempt::class,          // async analytics (analytics queue)
            UpdateTestDenormalizedCounts::class, // async counts (analytics queue)
            LogAuditTrail::class,                // sync: audit log
        ],
        QuestionsImported::class => [
            LogAuditTrail::class,
        ],
        StudentCreated::class => [
            LogAuditTrail::class,
        ],
        SubscriptionExpiring::class => [
            SendExpiryNotification::class,
        ],

        // ── EXAM LIFECYCLE ────────────────────────────────────────────────────
        ExamStarted::class => [
            LogExamActivity::class,         // async: analytics queue
            UpdateMonitoringState::class,   // async: monitoring queue — warms up Redis monitoring data
        ],
        OfflineAnswersSynced::class => [
            LogExamActivity::class,
        ],
        ExamSubmitted::class => [
            LogExamActivity::class,
            LogAuditTrail::class,           // sync: audit log
            UpdateMonitoringState::class,   // async: monitoring queue — moves attempt to submitted
        ],
        ExamAutoSubmitted::class => [
            LogExamActivity::class,
            NotifyAutoSubmit::class,        // async: notifications queue
            UpdateMonitoringState::class,   // async: monitoring queue
        ],
        ExamExpired::class => [
            LogExamActivity::class,
            UpdateMonitoringState::class,   // async: monitoring queue
        ],

        // ── HIGH-FREQUENCY EVENTS ─────────────────────────────────────────────
        // AnswerSaved (~2k/s) and AutoSaveCompleted (~2k/s): no listeners.
        // Answered-count is maintained atomically by ExamStateCacheService::adjustAnsweredCount()
        // (called from ExamEngineController on every save). The monitoring read service
        // reads from that same hash — no duplication needed here.
        AnswerSaved::class => [],
        AutoSaveCompleted::class => [],

        // ExamHeartbeatReceived (~667/s): UpdateHeartbeatStatus is SYNCHRONOUS
        // (no ShouldQueue) — it only does a single Redis SETEX (< 1 ms) in-process.
        // Making it async would push 667 queue jobs/second. Synchronous is correct.
        ExamHeartbeatReceived::class => [
            UpdateHeartbeatStatus::class,   // sync: Redis SETEX, 90s TTL
        ],

        // ── RESULTS ───────────────────────────────────────────────────────────
        ResultCalculated::class => [
            NotifyResultReady::class,       // async: notifications queue
            LogExamActivity::class,
        ],
        ResultPublished::class => [
            NotifyResultReady::class,
            LogExamActivity::class,
        ],
        RankGenerated::class => [
            LogExamActivity::class,
        ],

        // ── AUTHENTICATION ────────────────────────────────────────────────────
        StudentLoggedIn::class => [
            LogAuditTrail::class,           // sync: audit log
            LogExamActivity::class,
        ],
        StudentLoggedOut::class => [
            LogAuditTrail::class,           // sync: audit log
        ],
        InstitutionUserLoggedIn::class => [
            LogAuditTrail::class,           // sync: audit log
            LogExamActivity::class,
        ],

        // ── SECURITY ──────────────────────────────────────────────────────────
        // LogSecurityEvent is synchronous — critical audit trails must not depend
        // on queue health. UpdateMonitoringState is async — feeds the live dashboard
        // and is non-critical (a missed event affects display only, not audit).
        SessionConflictDetected::class => [
            LogSecurityEvent::class,        // sync: immediate security log
        ],
        TabSwitchDetected::class => [
            LogSecurityEvent::class,
            UpdateMonitoringState::class,   // async: monitoring queue — shows on dashboard
        ],
        FullscreenExitDetected::class => [
            LogSecurityEvent::class,
            UpdateMonitoringState::class,
        ],
        SuspiciousActivityDetected::class => [
            LogSecurityEvent::class,
            UpdateMonitoringState::class,
        ],
    ];

    // EvaluateTestAttempt chains UpdateStudentStats, UpdateQuestionStats,
    // UpdateBatchTestStats internally to ensure ordering. No separate listeners.

    public function boot(): void {}

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
