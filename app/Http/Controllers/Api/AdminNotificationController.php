<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Str;

class AdminNotificationController extends Controller
{
    // access control
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // view
    public function index(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = DB::table('notifications')
                ->where('user_id', $request->user()->id);

            // SERVER-SIDE SEARCH LOGIC
            if ($request->has('search') && !empty($request->search)) {
                $search = strtolower($request->search);
                $query->where('description', 'LIKE', "%{$search}%");
            }

            // DYNAMIC PAGINATION
            $entries = $request->has('entries') ? (int) $request->entries : 10;
            $notifications = $query->orderBy('created_at', 'desc')->paginate($entries);

            $totalUnread = DB::table('notifications')
                ->where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->count();

            return response()->json([
                'data' => $notifications->items(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => $totalUnread 
            ], 200);

        } catch (\Exception $e) {
            Log::error('AdminNotificationController index Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching notifications.'], 500);
        }
    }

    // Selected Notification Read
    public function markAsRead(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            DB::table('notifications')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->update(['is_read' => true, 'updated_at' => now()]);

            return response()->json(['message' => 'Marked as read'], 200);
        } catch (\Exception $e) {
            Log::error('AdminNotificationController markAsRead Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while updating notification.'], 500);
        }
    }

    // All Notification Read
    public function markAllAsRead(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            DB::table('notifications')
                ->where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->update(['is_read' => true, 'updated_at' => now()]);

            return response()->json(['message' => 'All marked as read'], 200);
        } catch (\Exception $e) {
            Log::error('AdminNotificationController markAllAsRead Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while updating notifications.'], 500);
        }
    }
}