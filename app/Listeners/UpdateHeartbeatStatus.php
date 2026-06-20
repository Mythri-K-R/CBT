<?php

namespace App\Listeners;

use App\Events\ExamHeartbeatReceived;
use App\Services\ExamEngine\ExamMonitoringService;

/**
 * Synchronous heartbeat listener — deliberately NOT ShouldQueue.
 *
 * WHY SYNCHRONOUS:
 *   ExamHeartbeatReceived fires ~667 times/second at peak load (20k students
 *   with 30-second timer-sync intervals). Making this async would push 667 jobs
 *   per second onto the monitoring queue, overwhelming Horizon. Instead, each
 *   timer-sync request does one extra SETEX to Redis (< 1 ms) in-process.
 *
 * What it does:
 *   Refreshes the TTL-based heartbeat key (mon:hb:{attemptId}, TTL = 90 s).
 *   If the key expires, getExamSnapshot() marks the student as 'disconnected'.
 */
class UpdateHeartbeatStatus
{
    public function __construct(private readonly ExamMonitoringService $monitoring) {}

    public function handle(ExamHeartbeatReceived $event): void
    {
        $this->monitoring->recordHeartbeat($event->attemptId);
    }
}
