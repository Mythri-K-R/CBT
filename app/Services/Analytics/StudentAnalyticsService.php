<?php

namespace App\Services\Analytics;

use App\Models\Student;
use App\Models\StudentChapterStats;
use App\Models\StudentSubjectStats;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use Illuminate\Support\Facades\DB;

class StudentAnalyticsService
{
    public function getStudentProfile(Student $student): array
    {
        $attempts = TestAttempt::where('student_id', $student->id)
            ->whereIn('status', ['submitted','completed'])
            ->with('test:id,title,test_category,total_marks,exam_type')
            ->latest('submitted_at')
            ->get();

        $subjectStats  = $student->subjectStats()->with('subject:id,name')->get();
        $chapterStats  = $student->chapterStats()->with('chapter:id,name,subject_id')->orderByDesc('accuracy')->get();

        $scoreTrend = $attempts->take(10)->map(fn ($a) => [
            'test'       => $a->test->title,
            'score'      => $a->total_score,
            'max_score'  => $a->max_possible_score,
            'percentage' => $a->percentage,
            'date'       => $a->submitted_at,
        ])->values();

        $totalAttempts = $attempts->count();
        $avgScore      = $totalAttempts ? round($attempts->avg('total_score'), 2) : 0;
        $avgAccuracy   = $totalAttempts ? round($attempts->avg('accuracy'), 2) : 0;
        $bestScore     = $attempts->max('total_score') ?? 0;
        $bestRank      = $attempts->min('rank_in_test') ?? null;

        $weakChapters = $chapterStats->filter(fn ($cs) => $cs->accuracy < 40 && $cs->total_questions >= 3);

        return [
            'student'       => $student->only(['id','name','roll_number']),
            'overview'      => compact('totalAttempts', 'avgScore', 'avgAccuracy', 'bestScore', 'bestRank'),
            'score_trend'   => $scoreTrend,
            'subject_stats' => $subjectStats,
            'chapter_stats' => $chapterStats->take(20),
            'weak_chapters' => $weakChapters->values()->take(5),
            'recent_tests'  => $attempts->take(5)->map(fn ($a) => [
                'test'         => $a->test->title,
                'score'        => $a->total_score,
                'total_marks'  => $a->max_possible_score,
                'rank'         => $a->rank_in_test,
                'percentile'   => $a->percentile,
                'submitted_at' => $a->submitted_at,
            ]),
        ];
    }

    public function updateStudentStats(int $studentId, int $testId): void
    {
        $responses = TestResponse::whereHas(
            'attempt',
            fn ($q) => $q->where('student_id', $studentId)->where('test_id', $testId)
        )->with('question:id,subject_id,chapter_id')->get();

        $bySubject = $responses->groupBy(fn ($r) => $r->question->subject_id);
        foreach ($bySubject as $subjectId => $group) {
            $correct = $group->filter(fn ($r) => $r->is_correct)->count();
            $wrong   = $group->filter(fn ($r) => $r->is_correct === false)->count();
            $total   = $correct + $wrong + $group->filter(fn ($r) => $r->is_correct === null)->count();

            StudentSubjectStats::updateOrCreate(
                ['student_id' => $studentId, 'subject_id' => $subjectId],
                [
                    'exam_type'       => $responses->first()->question->subject->exam_type ?? 'neet',
                    'total_questions' => DB::raw("total_questions + {$total}"),
                    'total_correct'   => DB::raw("total_correct + {$correct}"),
                    'total_wrong'     => DB::raw("total_wrong + {$wrong}"),
                    'accuracy'        => ($correct + $wrong) > 0 ? round($correct / ($correct + $wrong) * 100, 2) : 0,
                ]
            );
        }

        $byChapter = $responses->groupBy(fn ($r) => $r->question->chapter_id);
        foreach ($byChapter as $chapterId => $group) {
            $correct = $group->filter(fn ($r) => $r->is_correct)->count();
            $wrong   = $group->filter(fn ($r) => $r->is_correct === false)->count();
            $total   = $correct + $wrong + $group->filter(fn ($r) => $r->is_correct === null)->count();

            StudentChapterStats::updateOrCreate(
                ['student_id' => $studentId, 'chapter_id' => $chapterId],
                [
                    'total_questions' => DB::raw("total_questions + {$total}"),
                    'total_correct'   => DB::raw("total_correct + {$correct}"),
                    'total_wrong'     => DB::raw("total_wrong + {$wrong}"),
                    'accuracy'        => ($correct + $wrong) > 0 ? round($correct / ($correct + $wrong) * 100, 2) : 0,
                ]
            );
        }
    }
}
