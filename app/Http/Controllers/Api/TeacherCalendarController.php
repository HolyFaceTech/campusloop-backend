<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Classwork;

class TeacherCalendarController extends Controller
{
    public function events(Request $request)
    {
        try {
            $teacherId = $request->user()->id;
            $events = [];

            // GET SYSTEM ANNOUNCEMENTS (Published or Done)
            $announcements = Announcement::with('files')
                ->where('publish_from', '<=', now())
                ->get()
                ->map(function ($a) {
                    return [
                        'id' => 'ann_' . $a->id,
                        'title' => $a->title,
                        'start' => $a->publish_from,
                        'end' => $a->valid_until,
                        'backgroundColor' => $a->status === 'Done' ? '#6c757d' : '#198754',
                        'extendedProps' => [
                            'type' => 'Announcement',
                            'status' => $a->status,
                            'content' => $a->content,
                            'link' => $a->link,
                            'files' => $a->files
                        ]
                    ];
                })->toArray();

            // GET CLASSWORK DEADLINES (Own Classrooms)
            $classworks = Classwork::with('classroom.subject')
                ->whereHas('classroom', function ($q) use ($teacherId) {
                    $q->where('creator_id', $teacherId);
                })
                ->whereNotNull('deadline')
                ->get()
                ->map(function ($cw) {
                    return [
                        'id' => 'cw_' . $cw->id,
                        'title' => $cw->title . ' (' . ($cw->classroom->subject->code ?? '') . ')',
                        'start' => $cw->deadline,
                        'backgroundColor' => '#dc3545',
                        'extendedProps' => [
                            'type' => 'Classwork',
                            'classroom_id' => $cw->classroom_id,
                            'content' => $cw->instruction,
                            'status' => 'Pending'
                        ]
                    ];
                })->toArray();

            // EXACT FORMAT PARSER PARA SA CLASSROOM SCHEDULES
            $dayMap = [
                'sunday' => 0, 'sun' => 0,
                'monday' => 1, 'mon' => 1,
                'tuesday' => 2, 'tue' => 2,
                'wednesday' => 3, 'wed' => 3,
                'thursday' => 4, 'thu' => 4,
                'friday' => 5, 'fri' => 5,
                'saturday' => 6, 'sat' => 6
            ];
            
            $classrooms = Classroom::with('subject')
                ->where('creator_id', $teacherId)
                ->get();

            $classroomEvents = [];
            foreach ($classrooms as $room) {
                $schedules = $room->schedule; 
                
                // I-convert sa array kung string ang na-return ng database
                if (is_string($schedules)) {
                    $schedules = json_decode($schedules, true);
                }

                // Format: {"days":["Mon","Wed","Fri"], "start_time":"19:30", "end_time":"20:30"}
                if (is_array($schedules) && isset($schedules['days']) && is_array($schedules['days'])) {
                    
                    $startTime = $schedules['start_time'] ?? null;
                    $endTime = $schedules['end_time'] ?? null;

                    // I-loop ang bawat araw sa loob ng "days" array
                    foreach ($schedules['days'] as $index => $dayName) {
                        $cleanDay = strtolower(trim($dayName)); 
                        
                        if (isset($dayMap[$cleanDay])) {
                            $event = [
                                'id' => 'class_' . $room->id . '_' . $index,
                                'title' => ($room->subject->code ?? 'Class') . ' - ' . $room->section,
                                'daysOfWeek' => [$dayMap[$cleanDay]], // Target day (0-6)
                                'backgroundColor' => $room->color_bg ?? '#6f42c1',
                                
                                // Para mag-appear sa buong calendar mula nakaraan hanggang future
                                'startRecur' => now()->subMonths(6)->format('Y-m-d'),
                                'endRecur' => now()->addMonths(6)->format('Y-m-d'),

                                'extendedProps' => [
                                    'type' => 'Classroom',
                                    'classroom_id' => $room->id,
                                    'content' => 'Regular Class Schedule',
                                    'status' => 'Published'
                                ]
                            ];

                            // FORCE 24-HOUR FORMAT para sa FullCalendar compatibility
                            if (!empty($startTime) && strtotime($startTime) !== false) {
                                $event['startTime'] = date('H:i:s', strtotime($startTime));
                            }
                            if (!empty($endTime) && strtotime($endTime) !== false) {
                                $event['endTime'] = date('H:i:s', strtotime($endTime));
                            }

                            $classroomEvents[] = $event;
                        }
                    }
                }
            }

            // Pagsama-samahin lahat ng events
            $events = array_merge($announcements, $classworks, $classroomEvents);

            return response()->json($events, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch calendar events: ' . $e->getMessage()], 500);
        }
    }
}