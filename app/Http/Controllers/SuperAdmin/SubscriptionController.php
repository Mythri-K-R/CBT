<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\SubscriptionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = SubscriptionHistory::with('institution:id,name,code')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->institution_id, fn ($q) => $q->where('institution_id', $request->institution_id))
            ->latest('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $subscriptions]);
    }

    public function update(Request $request, Institution $institution): JsonResponse
    {
        $data = $request->validate([
            'plan'              => 'required|in:trial,starter,growth,enterprise',
            'subscription_end'  => 'required|date|after:today',
            'amount'            => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string',
            'student_limit'     => 'nullable|integer|min:1',
            'faculty_limit'     => 'nullable|integer|min:1',
            'question_limit'    => 'nullable|integer|min:1',
        ]);

        $planConfig = config("examsphere.plans.{$data['plan']}");

        // Expire current active subscription
        SubscriptionHistory::where('institution_id', $institution->id)
                           ->where('status', 'active')
                           ->update(['status' => 'upgraded']);

        SubscriptionHistory::create([
            'institution_id'     => $institution->id,
            'plan'               => $data['plan'],
            'student_limit'      => $data['student_limit'] ?? $planConfig['student_limit'],
            'amount'             => $data['amount'] ?? $planConfig['price'],
            'currency'           => 'INR',
            'payment_reference'  => $data['payment_reference'] ?? null,
            'start_date'         => today(),
            'end_date'           => $data['subscription_end'],
            'status'             => 'active',
        ]);

        $institution->update([
            'plan'               => $data['plan'],
            'student_limit'      => $data['student_limit'] ?? $planConfig['student_limit'],
            'faculty_limit'      => $data['faculty_limit'] ?? $planConfig['faculty_limit'],
            'question_limit'     => $data['question_limit'] ?? $planConfig['question_limit'],
            'subscription_start' => today(),
            'subscription_end'   => $data['subscription_end'],
            'is_active'          => true,
        ]);

        return response()->json(['success' => true, 'message' => 'Subscription updated.', 'data' => $institution]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $year  = $request->year ?? now()->year;
        $month = $request->month;

        $query = SubscriptionHistory::whereYear('created_at', $year)
                                    ->whereIn('status', ['active','expired']);

        if ($month) {
            $query->whereMonth('created_at', $month);
        }

        $total   = $query->sum('amount');
        $byPlan  = $query->select('plan', DB::raw('sum(amount) as revenue'), DB::raw('count(*) as count'))
                          ->groupBy('plan')
                          ->get();
        $monthly = SubscriptionHistory::whereYear('created_at', $year)
                                       ->whereIn('status', ['active','expired'])
                                       ->select(DB::raw('MONTH(created_at) as month'), DB::raw('sum(amount) as revenue'))
                                       ->groupBy(DB::raw('MONTH(created_at)'))
                                       ->orderBy('month')
                                       ->get();

        return response()->json([
            'success' => true,
            'data'    => compact('total', 'byPlan', 'monthly'),
        ]);
    }
}
