<?php

namespace App\Providers;

use App\Events\TestStarted;
use App\Events\TestSubmitted;
use App\Events\QuestionsImported;
use App\Events\StudentCreated;
use App\Events\SubscriptionExpiring;
use App\Listeners\EvaluateTestAttempt;
use App\Listeners\UpdateStudentStats;
use App\Listeners\UpdateQuestionStats;
use App\Listeners\UpdateBatchTestStats;
use App\Listeners\UpdateTestDenormalizedCounts;
use App\Listeners\LogAuditTrail;
use App\Listeners\SendExpiryNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        TestStarted::class => [
            LogAuditTrail::class,
        ],
        TestSubmitted::class => [
            EvaluateTestAttempt::class,
            UpdateTestDenormalizedCounts::class,
            LogAuditTrail::class,
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
    ];

    // After evaluation completes (fired inside EvaluateTestAttempt listener):
    // - UpdateStudentStats
    // - UpdateQuestionStats
    // - UpdateBatchTestStats
    // These are chained manually inside EvaluateTestAttempt to ensure ordering.

    public function boot(): void {}

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
