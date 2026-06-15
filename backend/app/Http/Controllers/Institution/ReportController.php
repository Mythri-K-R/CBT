<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReport;
use App\Models\Student;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function testReport(Request $request, Test $test): JsonResponse
    {
        $request->validate([
            'format'   => 'nullable|in:pdf,excel',
            'batch_id' => 'nullable|exists:batches,id',
        ]);

        $jobId = uniqid('report_test_');
        GenerateReport::dispatch('test', $test->id, $request->user()->id, [
            'format'   => $request->format ?? 'pdf',
            'batch_id' => $request->batch_id,
            'job_id'   => $jobId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report generation started.',
            'job_id'  => $jobId,
        ], 202);
    }

    public function studentReport(Request $request, Student $student): JsonResponse
    {
        $request->validate(['format' => 'nullable|in:pdf,excel']);

        $jobId = uniqid('report_student_');
        GenerateReport::dispatch('student', $student->id, $request->user()->id, [
            'format' => $request->format ?? 'pdf',
            'job_id' => $jobId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report generation started.',
            'job_id'  => $jobId,
        ], 202);
    }

    public function download(Request $request, string $report): JsonResponse
    {
        $path = "reports/{$report}";
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'Report not ready yet.'], 404);
        }

        return response()->json([
            'success' => true,
            'url'     => Storage::temporaryUrl($path, now()->addMinutes(15)),
        ]);
    }
}
