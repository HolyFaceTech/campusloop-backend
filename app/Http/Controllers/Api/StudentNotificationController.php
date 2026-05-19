<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 

class StudentNotificationController extends Controller
{
    // RBAC
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // View all notifications for student
    public function index(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $userId = $request->user()->id;

            $unreadCount = DB::table('notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->count();

            $query = DB::table('notifications')->where('user_id', $userId);

            // SERVER-SIDE SEARCH
            if ($request->has('search') && !empty($request->search)) {
                $search = strtolower($request->search);
                $query->where('description', 'LIKE', "%{$search}%");
            }

            // SERVER-SIDE PAGINATION
            $entries = $request->input('entries', 10);
            $notifications = $query->orderBy('created_at', 'desc')->paginate($entries);

            return response()->json([
                'data' => $notifications->items(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => $unreadCount 
            ], 200);

        } catch (\Exception $e) {
            Log::error('Student Fetch Notifications Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Mark single notification as read
    public function markAsRead(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access.'], 403);
        }

        try {
            DB::table('notifications')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->update(['is_read' => true, 'updated_at' => now()]);

            return response()->json(['message' => 'Marked as read'], 200);
        } catch (\Exception $e) {
            Log::error('Student Mark Read Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Mark all notifications as read
    public function markAllAsRead(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access.'], 403);
        }

        try {
            DB::table('notifications')
                ->where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->update(['is_read' => true, 'updated_at' => now()]);

            return response()->json(['message' => 'All marked as read'], 200);
        } catch (\Exception $e) {
            Log::error('Student Mark All Read Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }
}