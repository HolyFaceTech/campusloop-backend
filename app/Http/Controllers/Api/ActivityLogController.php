<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivityLog; // Siguraduhing may ActivityLog model ka na ah!

class ActivityLogController extends Controller
{
    public function indexAdmin()
    {
        try {
            // Kukunin lahat ng logs at isasama ang relationship na 'user'
            $logs = ActivityLog::with('user')->orderBy('created_at', 'desc')->get()->map(function ($log) {
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

            return response()->json($logs, 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to load activity logs.', 'error' => $e->getMessage()], 500);
        }
    }
}