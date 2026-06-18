<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $questions = Question::with(['subject:id,name', 'chapter:id,name', 'topic:id,name'])
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->when($request->subject_id, fn ($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->chapter_id, fn ($q) => $q->where('chapter_id', $request->chapter_id))
            ->when($request->topic_id, fn ($q) => $q->where('topic_id', $request->topic_id))
            ->when($request->difficulty, fn ($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->whereFullText('question_text', $request->search))
            ->latest()
            ->paginate(25);

        return response()->json(['success' => true, 'data' => $questions]);
    }

    public function show(Question $question): JsonResponse
    {
        $question->load(['subject', 'chapter', 'topic', 'images', 'creator:id,name']);
        return response()->json(['success' => true, 'data' => $question]);
    }

    public function store(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;

        if (!$institution->isWithinQuestionLimit()) {
            return response()->json([
                'message' => "Question limit ({$institution->question_limit}) reached. Upgrade your plan.",
            ], 422);
        }

        $data = $this->validateQuestion($request);
        $data['created_by']  = $request->user()->id;
        $data['source_type'] = 'manual';

        $question = Question::create($data);
        return response()->json(['success' => true, 'data' => $question], 201);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        $question->update($this->validateQuestion($request, false));
        return response()->json(['success' => true, 'data' => $question]);
    }

    public function destroy(Question $question): JsonResponse
    {
        $question->delete();
        return response()->json(['success' => true]);
    }

    private function validateQuestion(Request $request, bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';
        return $request->validate([
            'exam_type'       => "{$r}|in:neet,jee_main,jee_advanced,kcet",
            'subject_id'      => "{$r}|exists:subjects,id",
            'chapter_id'      => "{$r}|exists:chapters,id",
            'topic_id'        => 'nullable|exists:topics,id',
            'difficulty'      => "{$r}|in:easy,medium,hard",
            'type'            => "{$r}|in:single_mcq,multiple_mcq,numerical,integer,assertion_reason,match_following",
            'question_text'   => "{$r}|string",
            'options'         => "{$r}|array",
            'correct_answer'  => "{$r}|string|max:100",
            'answer_tolerance'=> 'nullable|numeric',
            'positive_marks'  => 'nullable|numeric|min:0',
            'negative_marks'  => 'nullable|numeric|min:0',
            'partial_marks'   => 'nullable|numeric|min:0',
            'explanation'     => 'nullable|string',
            'source'          => 'nullable|string|max:255',
            'tags'            => 'nullable|array',
            'language'        => 'nullable|in:english,hindi,kannada',
            'has_latex'       => 'nullable|boolean',
            'status'          => 'nullable|in:draft,active',
        ]);
    }
}
