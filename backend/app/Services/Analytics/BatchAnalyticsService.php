<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\BatchTestStats;
use App\Models\TestAttempt;
use Illuminate\Support\Facades\DB;

class BatchAnalyticsService
{
    public function getBatchProfile(Batch $batch): array
    {
        $students = $batch->students()->get(['students.id']);
        $studentIds = $students->pluck('id');

        if ($studentIds->isEmpty()) {
            return ['batch' => $batch->only(['id','name']), 'no_data' => true];
        }

        $attempts = TestAttempt::whereIn('student_id', $studentIds)
            ->whereIn('status', ['submitted','completed'])
            ->with('test:id,title,total_marks')
            ->get();

        $testStats = $batch->batchTestStats()->with('test:id,title')->orderBy('created_at')->get();

        $topStudents = TestAttempt::whereIn('student_id', $studentIds)
            ->whereIn('status', ['submitted','completed'])
            ->select('student_id', DB::raw('AVG(percentage) as avg_pct'), DB::raw('count(*) as tests_taken'))
            ->groupBy('student_id')
            ->with('student:id,name,roll_number')
            ->orderByDesc('avg_pct')
            ->limit(10)
            ->get();

        return [
            'batch'        => $batch->only(['id','name','exam_type','batch_type']),
            'student_count'=> $studentIds->count(),
            'test_stats'   => $testStats,
            'top_students' => $topStudents,
            'overall'      => [
                'total_attempts' => $attempts->count(),
                'avg_score'      => round($attempts->avg('total_score'), 2),
                'avg_accuracy'   => round($attempts->avg('accuracy'), 2),
            ],
        ];
    }

    public function getOverview(int $institutionId): array
    {
        $batchStats = DB::table('batches')
            ->where('batches.institution_id', $institutionId)
            ->where('batches.status', 'active')
            ->leftJoin('batch_test_stats', 'batches.id', '=', 'batch_test_stats.batch_id')
            ->select('batches.id', 'batches.name', 'batches.exam_type',
                DB::raw('COUNT(batch_test_stats.id) as tests_count'),
                DB::raw('AVG(batch_test_stats.avg_score) as avg_score'),
                DB::raw('AVG(batch_test_stats.avg_accuracy) as avg_accuracy'))
            ->groupBy('batches.id', 'batches.name', 'batches.exam_type')
            ->get();

        return ['batches' => $batchStats];
    }

    public function updateBatchStats(int $testId): void
    {
        $testBatches = \App\Models\TestBatch::where('test_id', $testId)->get();

        foreach ($testBatches as $tb) {
            $studentIds = \App\Models\BatchStudent::where('batch_id', $tb->batch_id)
                                                   ->where('status', 'active')
                                                   ->pluck('student_id');

            $stats = TestAttempt::where('test_id', $testId)
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', ['submitted','completed'])
                ->selectRaw('count(*) as attempted, avg(total_score) as avg_score, avg(accuracy) as avg_accuracy, max(total_score) as max_score, min(total_score) as min_score')
                ->first();

            BatchTestStats::updateOrCreate(
                ['batch_id' => $tb->batch_id, 'test_id' => $testId],
                [
                    'total_assigned'  => $studentIds->count(),
                    'total_attempted' => $stats->attempted ?? 0,
                    'avg_score'       => round($stats->avg_score ?? 0, 2),
                    'avg_accuracy'    => round($stats->avg_accuracy ?? 0, 2),
                    'highest_score'   => $stats->max_score ?? 0,
                    'lowest_score'    => $stats->min_score ?? 0,
                ]
            );
        }
    }
}
