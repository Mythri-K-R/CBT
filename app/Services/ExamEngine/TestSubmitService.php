<?php

namespace App\Services\ExamEngine;

use App\Events\TestSubmitted;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use Illuminate\Support\Facades\DB;

class TestSubmitService
{
    public function submit(TestAttempt $attempt, string $submissionType = 'manual'): array
    {
        return DB::transaction(function () use ($attempt, $submissionType) {
            $responses = $attempt->responses()->with('question')->get();

            $totalScore         = 0.0;
            $maxPossibleScore   = 0.0;
            $totalCorrect       = 0;
            $totalWrong         = 0;
            $totalUnattempted   = 0;
            $totalMarkedReview  = 0;
            $subjectScores      = [];
            $timeSpent          = 0;

            foreach ($responses as $response) {
                $question        = $response->question;
                $maxPossibleScore += $question->positive_marks;

                if (in_array($response->status, ['marked_review', 'answered_marked_review'])) {
                    $totalMarkedReview++;
                }

                $timeSpent += $response->time_spent_seconds;

                if (!$response->isAnswered()) {
                    $totalUnattempted++;
                    $marksAwarded = 0;
                    $isCorrect    = null;
                } else {
                    $marksAwarded = $question->calculateMarks($response->selected_answer);
                    $isCorrect    = $marksAwarded > 0;

                    if ($isCorrect) {
                        $totalCorrect++;
                    } else {
                        $totalWrong++;
                    }
                    $totalScore += $marksAwarded;
                }

                // Update the response with evaluation
                $response->update([
                    'is_correct'   => $isCorrect,
                    'marks_awarded'=> $marksAwarded,
                ]);

                // Aggregate by subject
                $subjectId = $question->subject_id;
                if (!isset($subjectScores[$subjectId])) {
                    $subjectScores[$subjectId] = [
                        'subject_id'    => $subjectId,
                        'subject_name'  => $question->subject->name ?? '',
                        'score'         => 0,
                        'max_score'     => 0,
                        'correct'       => 0,
                        'wrong'         => 0,
                        'unattempted'   => 0,
                        'time_seconds'  => 0,
                    ];
                }
                $subjectScores[$subjectId]['score']        += $marksAwarded;
                $subjectScores[$subjectId]['max_score']    += $question->positive_marks;
                $subjectScores[$subjectId]['time_seconds'] += $response->time_spent_seconds;
                if ($isCorrect)                    $subjectScores[$subjectId]['correct']++;
                elseif ($isCorrect === false)      $subjectScores[$subjectId]['wrong']++;
                else                               $subjectScores[$subjectId]['unattempted']++;
            }

            // Calculate derived stats
            $attempted   = $totalCorrect + $totalWrong;
            $accuracy    = $attempted > 0 ? round($totalCorrect / $attempted * 100, 2) : 0;
            $percentage  = $maxPossibleScore > 0 ? round($totalScore / $maxPossibleScore * 100, 2) : 0;

            foreach ($subjectScores as &$ss) {
                $attempted = $ss['correct'] + $ss['wrong'];
                $ss['accuracy'] = $attempted > 0 ? round($ss['correct'] / $attempted * 100, 2) : 0;
            }

            $attempt->update([
                'status'               => 'submitted',
                'submitted_at'         => now(),
                'submission_type'      => $submissionType,
                'time_spent_seconds'   => $timeSpent,
                'total_score'          => $totalScore,
                'max_possible_score'   => $maxPossibleScore,
                'percentage'           => $percentage,
                'total_correct'        => $totalCorrect,
                'total_wrong'          => $totalWrong,
                'total_unattempted'    => $totalUnattempted,
                'total_marked_review'  => $totalMarkedReview,
                'accuracy'             => $accuracy,
                'subject_scores'       => array_values($subjectScores),
            ]);

            event(new TestSubmitted($attempt));

            return [
                'score'         => $totalScore,
                'max_score'     => $maxPossibleScore,
                'percentage'    => $percentage,
                'total_correct' => $totalCorrect,
                'total_wrong'   => $totalWrong,
                'accuracy'      => $accuracy,
                'show_result'   => $attempt->test->show_result_immediately,
            ];
        });
    }
}
