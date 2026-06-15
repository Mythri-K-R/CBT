<?php

namespace App\Services\QuestionBank;

use App\Models\TestResponse;
use Illuminate\Support\Facades\DB;

class QuestionStatsService
{
    public function updateStatsForAttempt(int $attemptId): void
    {
        $responses = TestResponse::where('attempt_id', $attemptId)->get();

        foreach ($responses as $response) {
            $correct  = $response->is_correct ? 1 : 0;
            $wrong    = ($response->is_correct === false) ? 1 : 0;
            $skipped  = ($response->is_correct === null) ? 1 : 0;

            DB::table('questions')->where('id', $response->question_id)->update([
                'times_used'    => DB::raw('times_used + 1'),
                'times_correct' => DB::raw("times_correct + {$correct}"),
                'times_wrong'   => DB::raw("times_wrong + {$wrong}"),
                'times_skipped' => DB::raw("times_skipped + {$skipped}"),
                'avg_time_seconds' => DB::raw("(avg_time_seconds * (times_used) + {$response->time_spent_seconds}) / (times_used + 1)"),
            ]);
        }
    }
}
