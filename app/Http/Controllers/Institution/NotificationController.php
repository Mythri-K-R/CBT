<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orWhere(function ($q) use ($request) {
                $q->whereNull('user_id')
                  ->where('institution_id', $request->user()->institution_id);
            })
            ->latest('created_at')
            ->paginate(20);

        $unread = Notification::where('user_id', $request->user()->id)->unread()->count();

        return response()->json([
            'success' => true,
            'data'    => $notifications,
            'unread'  => $unread,
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $notification->markAsRead();
        return response()->json(['success' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)->unread()
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
