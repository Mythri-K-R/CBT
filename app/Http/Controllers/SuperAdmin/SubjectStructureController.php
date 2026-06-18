<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectStructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Subject::with('chapters.topics')
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->when(!request()->user()?->isSuperAdmin(), fn ($q) => $q->whereNull('institution_id'))
            ->orderBy('exam_type')->orderBy('display_order')
            ->get();

        return response()->json(['success' => true, 'data' => $subjects]);
    }

    public function chapters(Subject $subject): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $subject->chapters()->with('topics')->get()]);
    }

    public function topics(Chapter $chapter): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $chapter->topics]);
    }

    public function storeSubject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_type'     => 'required|in:neet,jee_main,jee_advanced,kcet',
            'name'          => 'required|string|max:255',
            'code'          => 'nullable|string|max:50',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $subject = Subject::create($data);
        return response()->json(['success' => true, 'data' => $subject], 201);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validate([
            'name'          => 'sometimes|string|max:255',
            'code'          => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
            'is_active'     => 'nullable|boolean',
        ]));
        return response()->json(['success' => true, 'data' => $subject]);
    }

    public function destroySubject(Subject $subject): JsonResponse
    {
        $subject->delete();
        return response()->json(['success' => true]);
    }

    public function storeChapter(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
        ]);
        $data['subject_id'] = $subject->id;
        $chapter = Chapter::create($data);
        return response()->json(['success' => true, 'data' => $chapter], 201);
    }

    public function updateChapter(Request $request, Chapter $chapter): JsonResponse
    {
        $chapter->update($request->validate([
            'name'          => 'sometimes|string',
            'code'          => 'nullable|string',
            'display_order' => 'nullable|integer',
            'is_active'     => 'nullable|boolean',
        ]));
        return response()->json(['success' => true, 'data' => $chapter]);
    }

    public function destroyChapter(Chapter $chapter): JsonResponse
    {
        $chapter->delete();
        return response()->json(['success' => true]);
    }

    public function storeTopic(Request $request, Chapter $chapter): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
        ]);
        $data['chapter_id'] = $chapter->id;
        $topic = Topic::create($data);
        return response()->json(['success' => true, 'data' => $topic], 201);
    }

    public function updateTopic(Request $request, Topic $topic): JsonResponse
    {
        $topic->update($request->validate([
            'name'          => 'sometimes|string',
            'code'          => 'nullable|string',
            'display_order' => 'nullable|integer',
            'is_active'     => 'nullable|boolean',
        ]));
        return response()->json(['success' => true, 'data' => $topic]);
    }

    public function destroyTopic(Topic $topic): JsonResponse
    {
        $topic->delete();
        return response()->json(['success' => true]);
    }
}
