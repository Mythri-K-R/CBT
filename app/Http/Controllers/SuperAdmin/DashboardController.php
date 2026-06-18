<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionEnquiry;
use App\Models\Student;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $stats = [
            'institutions' => [
                'total'      => Institution::withTrashed()->count(),
                'active'     => Institution::where('is_active', true)->count(),
                'trial'      => Institution::where('plan', 'trial')->count(),
                'paid'       => Institution::whereIn('plan', ['starter','growth','enterprise'])->count(),
                'expiring_soon' => Institution::where('subscription_end', '<=', now()->addDays(30))
                                              ->where('subscription_end', '>', now())
                                              ->where('is_active', true)->count(),
            ],
            'users' => [
                'total_admins'  => User::where('role', 'institution_admin')->count(),
                'total_faculty' => User::where('role', 'faculty')->count(),
            ],
            'students' => [
                'total' => Student::count(),
            ],
            'tests' => [
                'total'   => Test::count(),
                'live'    => Test::where('status', 'live')->count(),
                'today'   => Test::whereDate('created_at', today())->count(),
            ],
            'attempts' => [
                'total'     => TestAttempt::count(),
                'today'     => TestAttempt::whereDate('started_at', today())->count(),
                'this_week' => TestAttempt::where('started_at', '>=', now()->startOfWeek())->count(),
            ],
            'leads' => [
                'new'      => InstitutionEnquiry::where('status', 'new_lead')->count(),
                'total'    => InstitutionEnquiry::count(),
            ],
        ];

        $recentInstitutions = Institution::latest()->take(5)->get(['id','name','code','plan','created_at']);
        $recentLeads        = InstitutionEnquiry::latest()->take(5)->get(['id','institution_name','email','status','created_at']);

        $planDistribution = Institution::where('is_active', true)
            ->select('plan', DB::raw('count(*) as count'))
            ->groupBy('plan')
            ->pluck('count', 'plan');

        return response()->json([
            'success' => true,
            'data'    => compact('stats', 'recentInstitutions', 'recentLeads', 'planDistribution'),
        ]);
    }
}
