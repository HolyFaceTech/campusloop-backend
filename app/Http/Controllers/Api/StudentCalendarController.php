<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Classwork;

class StudentCalendarController extends Controller
{
    public function events(Request $request)
    {
        try {
            $studentId = $request->user()->id;
            $events = [];

            // 1. GET SYSTEM ANNOUNCEMENTS (Published or Done)
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

            // 2. GET CLASSWORK DEADLINES (Mula sa mga SINALIAN na Classrooms na Approved)
            $classworks = Classwork::with('classroom.subject')
                ->whereHas('classroom.students', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)
                      ->where('classroom_student.status', 'approved');
                })
                ->whereNotNull('deadline')
                ->get()
                ->map(function ($cw) {
                    return [
                        'id' => 'cw_' . $cw->id,
                        'title' => $cw->title . ' (' . ($cw->classroom->subject->code ?? '') . ')',
                        'start' => $cw->deadline,
                        'backgroundColor' => '#dc3545', // Danger/Red para sa Deadlines
                        'extendedProps' => [
                            'type' => 'Classwork',
                            'classroom_id' => $cw->classroom_id,
                            'content' => $cw->instruction,
                            'status' => 'Pending'
                        ]
                    ];
                })->toArray();

            // 3. INDESTRUCTIBLE CLASSROOM SCHEDULES PARSER (Mula sa SINALIAN na Classrooms)
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
                ->whereHas('students', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)
                      ->where('classroom_student.status', 'approved');
                })
                ->get();

            $classroomEvents = [];
            foreach ($classrooms as $room) {
                $schedules = $room->schedule; 
                
                if (is_string($schedules)) {
                    $decoded = json_decode($schedules, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $schedules = $decoded;
                    } else {
                        $schedules = array_map('trim', explode(',', $schedules));
                    }
                }

                if (is_array($schedules) && isset($schedules['days']) && is_array($schedules['days'])) {
                    $startTime = $schedules['start_time'] ?? null;
                    $endTime = $schedules['end_time'] ?? null;

                    foreach ($schedules['days'] as $index => $dayName) {
                        $cleanDay = strtolower(trim($dayName)); 
                        
                        if (isset($dayMap[$cleanDay])) {
                            $event = [
                                'id' => 'class_' . $room->id . '_' . $index,
                                'title' => ($room->subject->code ?? 'Class') . ' - ' . $room->section,
                                'daysOfWeek' => [$dayMap[$cleanDay]],
                                'backgroundColor' => $room->color_bg ?? '#6f42c1',
                                'startRecur' => now()->subMonths(6)->format('Y-m-d'),
                                'endRecur' => now()->addMonths(6)->format('Y-m-d'),
                                'extendedProps' => [
                                    'type' => 'Classroom',
                                    'classroom_id' => $room->id,
                                    'content' => 'Regular Class Schedule',
                                    'status' => 'Published'
                                ]
                            ];

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

            $events = array_merge($announcements, $classworks, $classroomEvents);

            return response()->json($events, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch calendar events: ' . $e->getMessage()], 500);
        }
    }
}