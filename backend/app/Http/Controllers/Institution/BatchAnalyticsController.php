<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Analytics\BatchAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchAnalyticsController extends Controller
{
    public function __construct(private readonly BatchAnalyticsService $analytics) {}

    public function show(Batch $batch): JsonResponse
    {
        $data = $this->analytics->getBatchProfile($batch);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function overview(Request $request): JsonResponse
    {
        $data = $this->analytics->getOverview($request->user()->institution_id);
        return response()->json(['success' => true, 'data' => $data]);
    }
}
