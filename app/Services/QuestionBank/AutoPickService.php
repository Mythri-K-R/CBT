<?php

namespace App\Services\QuestionBank;

use App\Models\Question;
use App\Models\Test;
use Illuminate\Support\Collection;

class AutoPickService
{
    /**
     * Auto-select questions based on config.
     *
     * Config format:
     * [
     *   ['chapter_id' => 5, 'count' => 10, 'difficulty' => 'medium', 'type' => 'single_mcq', 'positive_marks' => 4, 'negative_marks' => 1, 'section_id' => null]
     * ]
     */
    public function pick(Test $test, array $config): Collection
    {
        $selected = collect();

        foreach ($config as $rule) {
            $query = Question::where('status', 'active')
                             ->where('exam_type', $test->exam_type);

            if (!empty($rule['chapter_id'])) {
                $query->where('chapter_id', $rule['chapter_id']);
            }
            if (!empty($rule['subject_id'])) {
                $query->where('subject_id', $rule['subject_id']);
            }
            if (!empty($rule['difficulty'])) {
                $query->where('difficulty', $rule['difficulty']);
            }
            if (!empty($rule['type'])) {
                $query->where('type', $rule['type']);
            }

            // Filter out already selected
            if ($selected->isNotEmpty()) {
                $query->whereNotIn('id', $selected->pluck('question_id'));
            }

            $count     = (int) ($rule['count'] ?? 1);
            $questions = $query->inRandomOrder()->limit($count)->get();

            foreach ($questions as $q) {
                $selected->push([
                    'question_id'    => $q->id,
                    'section_id'     => $rule['section_id'] ?? null,
                    'positive_marks' => $rule['positive_marks'] ?? $q->positive_marks,
                    'negative_marks' => $rule['negative_marks'] ?? $q->negative_marks,
                ]);
            }
        }

        return $selected;
    }
}
