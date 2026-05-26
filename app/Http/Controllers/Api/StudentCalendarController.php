<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Classwork;
use Illuminate\Support\Facades\Log; 
use Carbon\Carbon; 

class StudentCalendarController extends Controller
{
    // RBAC
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // view all schedule
    public function events(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $startDate = $request->input('start') ? Carbon::parse($request->input('start')) : now()->subMonths(3);
            $endDate = $request->input('end') ? Carbon::parse($request->input('end')) : now()->addMonths(6);

            $events = [];

            // ANNOUNCEMENTS
            $announcements = Announcement::with('files')
                ->where('publish_from', '<=', now())
                ->whereBetween('publish_from', [$startDate, $endDate]) 
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

            // CLASSWORK DEADLINES
            $classworks = Classwork::with('classroom.subject')
                ->whereHas('classroom.students', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)
                      ->where('classroom_student.status', 'approved');
                })
                ->whereNotNull('deadline')
                ->whereBetween('deadline', [$startDate, $endDate]) 
                ->get()
                ->map(function ($cw) {
                    return [
                        'id' => 'cw_' . $cw->id,
                        'title' => $cw->title . ' (' . ($cw->classroom->subject->code ?? '') . ')',
                        'start' => $cw->deadline,
                        'backgroundColor' => '#dc3545', // Red
                        'extendedProps' => [
                            'type' => 'Classwork',
                            'classroom_id' => $cw->classroom_id,
                            'content' => $cw->instruction,
                            'status' => 'Pending' 
                        ]
                    ];
                })->toArray();

            // CLASSROOM SCHEDULES
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
                                'startRecur' => $startDate->format('Y-m-d'), 
                                'endRecur' => $endDate->format('Y-m-d'),
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
            Log::error('Student Calendar Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while loading the calendar.'], 500);
        }
    }
}