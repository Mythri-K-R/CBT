<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ExamTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ExamTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = ExamTemplate::with('sections')
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->whereNull('institution_id')
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function show(ExamTemplate $examTemplate): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $examTemplate->load('sections.subject')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'exam_type'               => 'required|in:neet,jee_main,jee_advanced,kcet',
            'total_duration_minutes'  => 'required|integer|min:1',
            'total_questions'         => 'required|integer|min:1',
            'total_marks'             => 'required|numeric|min:1',
            'has_sectional_timing'    => 'nullable|boolean',
            'allow_section_switching' => 'nullable|boolean',
            'instructions'            => 'nullable|string',
            'sections'                => 'required|array|min:1',
            'sections.*.name'         => 'required|string',
            'sections.*.subject_id'   => 'nullable|exists:subjects,id',
            'sections.*.question_count' => 'required|integer|min:1',
            'sections.*.positive_marks' => 'required|numeric',
            'sections.*.negative_marks' => 'required|numeric',
            'sections.*.question_type'  => 'required|in:single_mcq,multiple_mcq,numerical,integer,assertion_reason,mixed',
        ]);

        $template = ExamTemplate::create(Arr::except($data, ['sections']));

        foreach ($data['sections'] as $order => $sectionData) {
            $template->sections()->create(array_merge($sectionData, ['display_order' => $order]));
        }

        return response()->json(['success' => true, 'data' => $template->load('sections')], 201);
    }

    public function update(Request $request, ExamTemplate $examTemplate): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string',
            'instructions'=> 'nullable|string',
            'is_active'   => 'nullable|boolean',
        ]);
        $examTemplate->update($data);
        return response()->json(['success' => true, 'data' => $examTemplate]);
    }

    public function destroy(ExamTemplate $examTemplate): JsonResponse
    {
        $examTemplate->delete();
        return response()->json(['success' => true]);
    }
}
