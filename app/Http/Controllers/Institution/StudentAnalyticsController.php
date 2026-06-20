<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Analytics\StudentAnalyticsService;
use Illuminate\Http\JsonResponse;

class StudentAnalyticsController extends Controller
{
    public function __construct(private readonly StudentAnalyticsService $analytics) {}

    public function show(Student $student): JsonResponse
    {
        $data = $this->analytics->getStudentProfile($student);
        return response()->json(['success' => true, 'data' => $data]);
    }
}
