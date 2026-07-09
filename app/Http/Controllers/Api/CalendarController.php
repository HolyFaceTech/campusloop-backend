<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    // security
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // Kukunin lahat ng Announcements at iko-convert sa Calendar Event format
    public function getAdminEvents(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $start = $request->query('start');
            $end = $request->query('end');
            $query = Announcement::with('files');

            // Kung may date range, i-filter lang ang mga pasok sa buwan na iyon
            if ($start && $end) {
                $query->where('publish_from', '<=', $end)
                      ->where('valid_until', '>=', $start);
            }

            $announcements = $query->get();
            
            $events = $announcements->map(function($a) {
                $status = $a->status;
                
                if ($status === 'Pending') {
                    $color = '#fd7e14'; // Orange
                } elseif ($status === 'Published') {
                    $color = '#198754'; // Green
                } else {
                    $color = '#6c757d'; // Gray (Done)
                }

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'start' => $a->publish_from,
                    'end' => $a->valid_until,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'extendedProps' => [
                        'type' => 'Announcement',
                        'content' => $a->content,
                        'status' => $status,
                        'link' => $a->link, 
                        'files' => $a->files 
                    ]
                ];
            });

            return response()->json($events, 200);
            
        } catch (\Exception $e) {
            Log::error('CalendarController getAdminEvents Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to load calendar events.'], 500);
        }
    }

    // Indicator Logic
    public function checkActiveIndicator(Request $request)
    {
        try {
            $now = now();
            
            $hasActive = Announcement::where('publish_from', '<=', $now)
                ->where('valid_until', '>=', $now)
                ->exists();

            return response()->json(['has_active_events' => $hasActive], 200);

        } catch (\Exception $e) {
            Log::error('CalendarController checkActiveIndicator Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to check indicators.'], 500);
        }
    }
}