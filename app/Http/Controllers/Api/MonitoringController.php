<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Services\ExamEngine\ExamMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Real-Time Exam Monitoring Controller  (Feature 5)
 *
 * Endpoints:
 *   GET /api/monitoring/exams/{testId}/stream    → SSE stream (3-second cadence)
 *   GET /api/monitoring/exams/{testId}/snapshot  → one-shot JSON snapshot
 *   GET /api/monitoring/overview                 → institution-level active exam list
 *   GET /api/monitoring/health                   → Redis + queue health
 *   GET /api/monitoring/recovery                 → circuit breakers + DLQ + MySQL (Feature 7)
 *
 * Transport: Server-Sent Events (text/event-stream).
 *   - No WebSockets, no Reverb required.
 *   - The service layer is Reverb-ready: all state is Redis-first and can be
 *     broadcast via channels with zero service-layer changes if WebSocket
 *     transport is added later.
 *
 * Multi-server: every PHP-FPM node reads from the same shared Redis DB0,
 * so the SSE stream is consistent regardless of which server handles the request.
 *
 * FPM note: each SSE connection holds one FPM worker for its duration.
 * For high institution-admin concurrency, configure a dedicated php-fpm pool
 * (e.g., pm.max_children = 50) for the /api/monitoring/* prefix in Nginx.
 */
class MonitoringController extends Controller
{
    public function __construct(private readonly ExamMonitoringService $monitoring) {}

    // ── SSE stream ────────────────────────────────────────────────────────────

    /**
     * Open a persistent SSE stream for one exam.
     *
     * Sends an exam_update event every 3 seconds. The client should reconnect
     * automatically on disconnect (EventSource does this natively).
     *
     * Authorization: one DB query at connection time; the loop is Redis-only.
     */
    public function streamExam(Request $request, int $testId): StreamedResponse
    {
        $this->authorizeTestAccess($testId);

        $monitoring = $this->monitoring; // capture for closure — avoids $this serialization

        return response()->stream(function () use ($testId, $monitoring) {
            // Disable all output buffering so data reaches the client immediately
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Allow the stream to run indefinitely (SSE is a long-lived connection)
            set_time_limit(0);

            $eventId = 0;

            // Send an initial keepalive comment so the client knows the stream is open
            echo ": connected\n\n";
            flush();

            while (!connection_aborted()) {
                try {
                    $snapshot = $monitoring->getExamSnapshot($testId);

                    echo "id: {$eventId}\n";
                    echo "event: exam_update\n";
                    echo 'data: ' . json_encode($snapshot) . "\n\n";
                    $eventId++;
                } catch (\Throwable) {
                    // Non-fatal — skip this tick; try again next cycle
                    echo "event: error\ndata: {\"message\":\"snapshot_unavailable\"}\n\n";
                }

                flush();

                // 3-second poll interval balances freshness vs. Redis load.
                // At 100 concurrent monitoring sessions: ~33 Redis pipeline reads/s.
                sleep(3);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // disable Nginx proxy buffering
            'Connection'        => 'keep-alive',
        ]);
    }

    // ── One-shot snapshots ────────────────────────────────────────────────────

    /**
     * Return the current monitoring snapshot for a test as JSON.
     *
     * Used for the initial dashboard render before the SSE stream connects,
     * and for clients that prefer polling over SSE.
     */
    public function snapshotExam(Request $request, int $testId): JsonResponse
    {
        $this->authorizeTestAccess($testId);

        return response()->json($this->monitoring->getExamSnapshot($testId));
    }

    /**
     * Institution-level overview: all tests with recent activity.
     *
     * Returns an array of per-test snapshots for tests that have at least one
     * active or submitted attempt in Redis. Tests with no activity are excluded.
     */
    public function overview(Request $request): JsonResponse
    {
        $institutionId = auth()->user()->institution_id;

        $testIds  = $this->monitoring->getInstitutionActiveTests($institutionId);
        $exams    = [];

        foreach ($testIds as $testId) {
            $snap = $this->monitoring->getExamSnapshot($testId);
            // Only include tests that have seen some activity
            if ($snap['active_count'] > 0 || $snap['submitted_count'] > 0) {
                $exams[] = $snap;
            }
        }

        // Sort: active exams first, then by submitted count descending
        usort($exams, fn ($a, $b) =>
            $b['active_count'] <=> $a['active_count']
                ?: $b['submitted_count'] <=> $a['submitted_count']
        );

        return response()->json(['exams' => $exams, 'ts' => now()->timestamp]);
    }

    // ── Health ────────────────────────────────────────────────────────────────

    /**
     * Redis + Horizon queue health check.
     *
     * Returns Redis ping latency, memory usage, and pending job counts per queue.
     * Intended for the institution admin's system health panel and internal ops.
     */
    public function health(): JsonResponse
    {
        $lastRun   = Cache::get('health:cron:auto_submit');
        $cronOk    = $lastRun && (now()->timestamp - $lastRun) <= 150;

        return response()->json([
            'redis'  => $this->monitoring->getRedisHealth(),
            'queues' => $this->monitoring->getQueueHealth(),
            'cron'   => [
                'auto_submit_status'  => $cronOk ? 'ok' : 'failed',
                'last_run_at'         => $lastRun ? date('Y-m-d H:i:s', $lastRun) : null,
                'seconds_since_run'   => $lastRun ? (now()->timestamp - $lastRun) : null,
            ],
            'ts'     => now()->timestamp,
        ]);
    }

    /**
     * Recovery monitoring dashboard — Feature 7.
     *
     * Returns a comprehensive resilience status snapshot:
     *   circuit_breakers  — CLOSED/OPEN/HALF_OPEN per dependency
     *   dlq               — failed job counts by category + alert status
     *   mysql             — connection count + reachability
     *   redis             — ping latency + memory
     *   queues            — pending jobs per queue
     *   overall_status    — 'healthy' | 'degraded' | 'critical'
     *
     * No live data (student answers, scores) is included — strictly infra.
     */
    public function recovery(): JsonResponse
    {
        return response()->json($this->monitoring->getRecoveryStatus());
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Verify the authenticated institution owns this test.
     *
     * One DB query, executed once at connection time — never inside the SSE loop.
     */
    private function authorizeTestAccess(int $testId): void
    {
        $institutionId = auth()->user()->institution_id ?? null;

        abort_unless(
            $institutionId && Test::where('id', $testId)
                ->where('institution_id', $institutionId)
                ->exists(),
            403,
            'Access denied to this exam.'
        );
    }
}
