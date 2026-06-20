<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessQuestionImport;
use App\Models\QuestionImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'       => 'required|file|mimes:xlsx,xls,csv,json,pdf|max:10240',
            'exam_type'  => 'required|in:neet,jee_main,jee_advanced,kcet',
            'subject_id' => 'nullable|exists:subjects,id',
            'chapter_id' => 'nullable|exists:chapters,id',
        ]);

        $ext  = $request->file('file')->getClientOriginalExtension();
        $path = $request->file('file')->store('platform-question-imports');

        $import = QuestionImport::create([
            'institution_id' => null,  // Platform-level
            'uploaded_by'    => $request->user()->id,
            'import_type'    => in_array($ext, ['xlsx','xls']) ? 'excel' : $ext,
            'file_path'      => $path,
            'exam_type'      => $request->exam_type,
            'subject_id'     => $request->subject_id,
            'chapter_id'     => $request->chapter_id,
            'status'         => 'pending',
        ]);

        ProcessQuestionImport::dispatch($import->id);

        return response()->json([
            'success'   => true,
            'message'   => 'Platform question import queued.',
            'import_id' => $import->id,
        ], 202);
    }

    public function show(QuestionImport $import): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $import->load('uploader:id,name')]);
    }

    public function index(Request $request): JsonResponse
    {
        $imports = QuestionImport::whereNull('institution_id')
            ->with('uploader:id,name')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $imports]);
    }
}
