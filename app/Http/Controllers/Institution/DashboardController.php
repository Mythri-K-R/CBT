<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Question;
use App\Models\Student;
use App\Models\Test;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;
        $user        = $request->user();

        $stats = [
            'students'  => Student::count(),
            'batches'   => Batch::where('status', 'active')->count(),
            'questions' => Question::where('status', 'active')->count(),
            'tests'     => [
                'total'     => Test::count(),
                'live'      => Test::where('status', 'live')->count(),
                'scheduled' => Test::where('status', 'scheduled')->count(),
                'completed' => Test::where('status', 'completed')->count(),
            ],
            'attempts' => [
                'today'     => TestAttempt::whereDate('started_at', today())->count(),
                'this_week' => TestAttempt::where('started_at', '>=', now()->startOfWeek())->count(),
            ],
            'limits' => [
                'students_used'  => Student::withTrashed()->count(),
                'students_limit' => $institution->student_limit,
                'faculty_used'   => $user->institution->users()->where('role', 'faculty')->count(),
                'faculty_limit'  => $institution->faculty_limit,
                'questions_used' => Question::withTrashed()->count(),
                'questions_limit'=> $institution->question_limit,
            ],
            'subscription' => [
                'plan'    => $institution->plan,
                'ends_at' => $institution->subscription_end,
                'active'  => $institution->isSubscriptionActive(),
                'days_remaining' => $institution->subscription_end
                    ? now()->diffInDays($institution->subscription_end, false)
                    : 0,
            ],
        ];

        $recentTests = Test::with('creator:id,name')
            ->latest()
            ->take(5)
            ->get(['id','uuid','title','status','test_category','total_attempts','created_at']);

        $recentAttempts = TestAttempt::with(['student:id,name,roll_number', 'test:id,title'])
            ->whereDate('started_at', today())
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => compact('stats', 'recentTests', 'recentAttempts'),
        ]);
    }
}
