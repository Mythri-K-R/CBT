<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $questions = Question::withoutGlobalScopes()
            ->whereNull('institution_id')
            ->with(['subject:id,name', 'chapter:id,name'])
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->when($request->subject_id, fn ($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->chapter_id, fn ($q) => $q->where('chapter_id', $request->chapter_id))
            ->when($request->difficulty, fn ($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->search, fn ($q) => $q->whereFullText('question_text', $request->search))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25);

        return response()->json(['success' => true, 'data' => $questions]);
    }

    public function show(Question $question): JsonResponse
    {
        $question->load(['subject', 'chapter', 'topic', 'images']);
        return response()->json(['success' => true, 'data' => $question]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateQuestion($request);
        $data['created_by']  = $request->user()->id;
        $data['source_type'] = 'manual';

        $question = Question::withoutGlobalScopes()->create($data);
        return response()->json(['success' => true, 'data' => $question], 201);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        $data = $this->validateQuestion($request, false);
        $question->update($data);
        return response()->json(['success' => true, 'data' => $question]);
    }

    public function destroy(Question $question): JsonResponse
    {
        $question->delete();
        return response()->json(['success' => true]);
    }

    private function validateQuestion(Request $request, bool $required = true): array
    {
        $rule = $required ? 'required' : 'sometimes';
        return $request->validate([
            'exam_type'      => "{$rule}|in:neet,jee_main,jee_advanced,kcet",
            'subject_id'     => "{$rule}|exists:subjects,id",
            'chapter_id'     => "{$rule}|exists:chapters,id",
            'topic_id'       => 'nullable|exists:topics,id',
            'difficulty'     => "{$rule}|in:easy,medium,hard",
            'type'           => "{$rule}|in:single_mcq,multiple_mcq,numerical,integer,assertion_reason,match_following",
            'question_text'  => "{$rule}|string",
            'options'        => "{$rule}|array",
            'correct_answer' => "{$rule}|string|max:100",
            'positive_marks' => "nullable|numeric|min:0",
            'negative_marks' => "nullable|numeric|min:0",
            'explanation'    => 'nullable|string',
            'source'         => 'nullable|string|max:255',
            'tags'           => 'nullable|array',
            'language'       => 'nullable|in:english,hindi,kannada',
            'has_latex'      => 'nullable|boolean',
            'status'         => 'nullable|in:draft,active,archived',
        ]);
    }
}
