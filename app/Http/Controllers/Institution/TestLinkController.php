<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\TestLink;
use App\Services\TestLink\LinkGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestLinkController extends Controller
{
    public function __construct(private readonly LinkGeneratorService $generator) {}

    public function index(Test $test): JsonResponse
    {
        $links = $test->links()->latest()->get();
        return response()->json(['success' => true, 'data' => $links]);
    }

    public function store(Request $request, Test $test): JsonResponse
    {
        $request->validate([
            'expires_at'   => 'nullable|date|after:now',
            'access_code'  => 'nullable|boolean',
        ]);

        $link = $this->generator->generate(
            $test,
            $request->user()->institution_id,
            $request->expires_at ?? $test->scheduled_end,
            (bool) $request->access_code
        );

        return response()->json(['success' => true, 'data' => $link], 201);
    }

    public function destroy(Test $test, TestLink $link): JsonResponse
    {
        $link->delete();
        return response()->json(['success' => true]);
    }

    public function toggle(Test $test, TestLink $link): JsonResponse
    {
        $link->update(['is_active' => !$link->is_active]);
        return response()->json(['success' => true, 'is_active' => $link->is_active]);
    }

    public function qrCode(Test $test, TestLink $link): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'qr_path'  => $link->qr_code_path,
            'url'      => $link->url,
            'slug'     => $link->slug,
        ]);
    }

    public function analytics(Test $test, TestLink $link): JsonResponse
    {
        $clicks = $link->clicks()
            ->selectRaw('action, count(*) as count, date(created_at) as date')
            ->groupBy('action', 'date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_clicks'      => $link->total_clicks,
                'total_starts'      => $link->total_starts,
                'total_completions' => $link->total_completions,
                'conversion_rate'   => $link->total_clicks > 0
                    ? round($link->total_completions / $link->total_clicks * 100, 1)
                    : 0,
                'timeline'          => $clicks,
            ],
        ]);
    }
}
