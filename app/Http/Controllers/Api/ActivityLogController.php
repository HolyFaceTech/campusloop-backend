<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivityLog; 
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Facades\DB; 

class ActivityLogController extends Controller
{
    // ACCESS CONTROL 
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    public function indexAdmin(Request $request) 
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = ActivityLog::with('user');

            // SERVER-SIDE SEARCH 
            if ($request->has('search') && !empty($request->search)) {
                $search = strtolower($request->search);
                $query->where(function($q) use ($search) {
                    $q->where('action', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('first_name', 'LIKE', "%{$search}%")
                                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                                    ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$search}%");
                      });
                });
            }

            $query->orderBy('created_at', 'desc');

            // SERVER-SIDE PAGINATION
            $entries = $request->has('entries') ? (int) $request->entries : 10;
            $paginatedLogs = $query->paginate($entries);

            // I-format lang ang mga nasa current page para mabilis
            $formattedLogs = collect($paginatedLogs->items())->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user_name' => $log->user ? $log->user->first_name . ' ' . $log->user->last_name : 'System / Deleted User',
                    'user_email' => $log->user ? $log->user->email : 'N/A',
                    'user_role' => $log->user ? $log->user->role : 'N/A',
                    'action' => $log->action,
                    'description' => $log->description,
                    'created_at' => $log->created_at,
                ];
            });

            return response()->json([
                'data' => $formattedLogs,
                'total' => $paginatedLogs->total(),
                'last_page' => $paginatedLogs->lastPage()
            ], 200);

        } catch (\Throwable $e) {
            Log::error('ActivityLogController indexAdmin Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load activity logs.'], 500);
        }
    }

    // Kukunin lang ang activity logs ng NAKA-LOGIN na user (Teacher/Student)
    public function indexUser(Request $request)
    {
        try {
            $entries = $request->has('entries') ? (int) $request->entries : 10;

            $paginatedLogs = ActivityLog::with('user')
                ->where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate($entries);

            $formattedLogs = collect($paginatedLogs->items())->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user_name' => $log->user ? $log->user->first_name . ' ' . $log->user->last_name : 'System / Deleted User',
                    'user_email' => $log->user ? $log->user->email : 'N/A',
                    'user_role' => $log->user ? $log->user->role : 'N/A',
                    'action' => $log->action,
                    'description' => $log->description,
                    'created_at' => $log->created_at,
                ];
            });

            return response()->json([
                'data' => $formattedLogs,
                'total' => $paginatedLogs->total(),
                'last_page' => $paginatedLogs->lastPage()
            ], 200);

        } catch (\Throwable $e) {
            Log::error('ActivityLogController indexUser Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load activity logs.'], 500);
        }
    }
}