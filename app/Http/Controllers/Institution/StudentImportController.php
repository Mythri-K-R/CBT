<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStudentImport;
use App\Models\QuestionImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'batch_id' => 'nullable|exists:batches,id',
        ]);

        $path = $request->file('file')->store('student-imports');

        $import = \App\Models\QuestionImport::create([
            'institution_id' => $request->user()->institution_id,
            'uploaded_by'    => $request->user()->id,
            'import_type'    => 'excel',
            'file_path'      => $path,
            'exam_type'      => 'neet',
            'status'         => 'pending',
        ]);

        ProcessStudentImport::dispatch($import->id, $request->batch_id);

        return response()->json([
            'success'   => true,
            'message'   => 'Import queued. Check status shortly.',
            'import_id' => $import->id,
        ], 202);
    }

    public function status(QuestionImport $import): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $import]);
    }
}
