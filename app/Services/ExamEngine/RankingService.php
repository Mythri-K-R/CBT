<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use Illuminate\Support\Facades\DB;

class RankingService
{
    public function calculateRankings(int $testId): void
    {
        $attempts = TestAttempt::where('test_id', $testId)
            ->whereIn('status', ['submitted','completed'])
            ->orderByDesc('total_score')
            ->orderBy('time_spent_seconds')
            ->get();

        $total = $attempts->count();

        DB::transaction(function () use ($attempts, $total) {
            $rank = 1;
            $prevScore  = null;
            $prevRank   = 1;

            foreach ($attempts as $index => $attempt) {
                if ($prevScore !== null && $attempt->total_score < $prevScore) {
                    $rank = $index + 1;
                }

                $percentile = round((($total - $rank) / $total) * 100, 2);

                $attempt->update([
                    'rank_in_test'      => $rank,
                    'total_participants'=> $total,
                    'percentile'        => $percentile,
                    'status'            => 'completed',
                ]);

                $prevScore = $attempt->total_score;
                $prevRank  = $rank;
            }
        });

        // Update test denormalized stats
        $stats = TestAttempt::where('test_id', $testId)
            ->whereIn('status', ['submitted','completed'])
            ->selectRaw('count(*) as total, avg(total_score) as avg_score, avg(accuracy) as avg_accuracy, max(total_score) as max_score, min(total_score) as min_score')
            ->first();

        \App\Models\Test::where('id', $testId)->update([
            'total_attempts' => $stats->total,
            'avg_score'      => round($stats->avg_score, 2),
            'avg_accuracy'   => round($stats->avg_accuracy, 2),
            'highest_score'  => $stats->max_score,
            'lowest_score'   => $stats->min_score,
        ]);
    }
}
