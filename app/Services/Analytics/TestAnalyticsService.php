<?php

namespace App\Services\Analytics;

use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use Illuminate\Support\Facades\DB;

class TestAnalyticsService
{
    public function analyze(Test $test): array
    {
        $attempts = TestAttempt::where('test_id', $test->id)
            ->whereIn('status', ['submitted','completed'])
            ->get();

        if ($attempts->isEmpty()) {
            return ['test' => $test->only(['id','title']), 'no_data' => true];
        }

        // Score distribution — 10 buckets
        $maxMarks = $test->total_marks;
        $buckets  = collect(range(0, 9))->map(fn ($i) => [
            'range' => ($i * 10).'%-'.(($i + 1) * 10 - 1).'%',
            'count' => 0,
        ]);

        foreach ($attempts as $attempt) {
            $pct = $maxMarks > 0 ? ($attempt->total_score / $maxMarks) * 100 : 0;
            $bucketIdx = min(9, (int) floor($pct / 10));
            $buckets[$bucketIdx]['count']++;
        }

        // Per-question stats (hardest / easiest)
        $questionStats = TestResponse::whereHas('attempt', fn ($q) => $q->where('test_id', $test->id)->whereIn('status', ['submitted','completed']))
            ->select('question_id',
                DB::raw('count(*) as total'),
                DB::raw('sum(case when is_correct = 1 then 1 else 0 end) as correct'),
                DB::raw('avg(time_spent_seconds) as avg_time'))
            ->groupBy('question_id')
            ->with('question:id,question_text,difficulty,subject_id')
            ->orderByRaw('(sum(case when is_correct = 1 then 1 else 0 end) / count(*)) ASC')
            ->limit(5)
            ->get()
            ->map(fn ($qs) => [
                'question_id'  => $qs->question_id,
                'text_snippet' => substr(strip_tags($qs->question->question_text ?? ''), 0, 80),
                'difficulty'   => $qs->question->difficulty,
                'accuracy'     => $qs->total > 0 ? round($qs->correct / $qs->total * 100, 1) : 0,
                'avg_time'     => round($qs->avg_time ?? 0),
            ]);

        return [
            'test'                  => $test->only(['id','title','total_questions','total_marks']),
            'total_attempts'        => $attempts->count(),
            'avg_score'             => round($attempts->avg('total_score'), 2),
            'avg_accuracy'          => round($attempts->avg('accuracy'), 2),
            'highest_score'         => $attempts->max('total_score'),
            'lowest_score'          => $attempts->min('total_score'),
            'score_distribution'    => $buckets->values(),
            'hardest_questions'     => $questionStats,
        ];
    }
}
