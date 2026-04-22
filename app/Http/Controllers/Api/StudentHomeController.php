<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Announcement;
use App\Models\Classwork;
use App\Models\Classroom;
use App\Models\Comment;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class StudentHomeController extends Controller
{
    // View and Fethcing Datas
    public function dashboard(Request $request)
    {
        $student = $request->user();
        $now = now();

        // GET PUBLISHED ANNOUNCEMENTS WITH COMMENTS
        $announcements = Announcement::with([
            'creator', 
            'files', 
            'comments' => function($q) {
                $q->whereNull('parent_id')
                  ->with(['user', 'replies.user'])
                  ->orderBy('created_at', 'asc');
            }
        ])
        ->where('publish_from', '<=', $now)
        ->where(function($q) use ($now) {
            $q->where('valid_until', '>=', $now)->orWhereNull('valid_until');
        })
        ->orderBy('publish_from', 'desc')
        ->get();

        // GET APPROVED CLASSROOMS (Count)
        $classrooms = Classroom::whereHas('students', function ($q) use ($student) {
            $q->where('student_id', $student->id)->where('classroom_student.status', 'approved');
        })->get();
        $classroomsCount = $classrooms->count();

        // GET TODAY'S SCHEDULES (Classes & Deadlines)
        $todayName = strtolower($now->format('l')); 
        $shortToday = strtolower($now->format('D')); 
        $todayDate = $now->format('Y-m-d');
        $todaySchedules = [];

        // Kunin ang Deadlines
        $deadlinesToday = Classwork::with('classroom.subject')
            ->whereIn('classroom_id', $classrooms->pluck('id'))
            ->whereDate('deadline', $todayDate)
            ->get();

        foreach ($deadlinesToday as $cw) {
            $todaySchedules[] = [
                'id' => 'cw_' . $cw->id,
                'classroom_id' => $cw->classroom_id,
                'title' => 'Deadline: ' . $cw->title,
                'type' => 'deadline',
                'time' => $cw->deadline->format('h:i A')
            ];
        }

        // Kunin ang Classes
        foreach ($classrooms as $room) {
            $room->load('subject');
            $scheds = $room->schedule;
            if (is_string($scheds)) {
                $decoded = json_decode($scheds, true);
                $scheds = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }
            if (is_array($scheds) && isset($scheds['days'])) {
                $days = array_map('strtolower', $scheds['days']);
                if (in_array($todayName, $days) || in_array($shortToday, $days)) {
                    $startTime = $scheds['start_time'] ?? null;
                    $endTime = $scheds['end_time'] ?? null;
                    $timeString = ($startTime ? date('h:i A', strtotime($startTime)) : 'TBA') . ' - ' . ($endTime ? date('h:i A', strtotime($endTime)) : 'TBA');
                    
                    $todaySchedules[] = [
                        'id' => 'class_' . $room->id,
                        'classroom_id' => $room->id,
                        'title' => ($room->subject->code ?? 'Class') . ' - ' . $room->section,
                        'type' => 'class',
                        'time' => $timeString
                    ];
                }
            }
        }

        // GET TO-DO LIST
        $classworks = Classwork::with(['classroom.subject', 'classwork_submissions' => function($q) use ($student) {
            $q->where('student_id', $student->id);
        }])
        ->whereIn('classroom_id', $classrooms->pluck('id'))
        ->where('type', '!=', 'material') 
        ->get();

        $todos = [];
        foreach ($classworks as $cw) {
            $submission = $cw->classwork_submissions->first();
            
            if ($submission) {
                // Scenario 1: Kung nai-update na sa database na literal na 'returned'
                if ($submission->status === 'returned') {
                    $statusCode = 'returned'; $indicator = 'secondary'; $label = 'RETURNED';
                } 
                // Kapag ginawang 'graded' ni Teacher
                elseif ($submission->status === 'graded') {
                    if (!is_null($submission->grade)) {
                        $statusCode = 'graded'; $indicator = 'success'; $label = 'GRADED';
                    } else {
                        // Graded ang status pero walang grade
                        $statusCode = 'returned'; $indicator = 'secondary'; $label = 'RETURNED';
                    }
                } 
                // Kapag na-late ipasa
                elseif ($submission->status === 'late_submission') {
                    $statusCode = 'done_late'; $indicator = 'warning'; $label = 'DONE LATE';
                } 
                // Kapag nanatiling 'pending'
                else {
                    // Kung 'pending' pa rin pero may isinulat na feedback si Teacher
                    if (!is_null($submission->teacher_feedback)) {
                        $statusCode = 'returned'; $indicator = 'secondary'; $label = 'RETURNED';
                    } else {
                        // Normal submission na hindi pa narereview
                        $statusCode = 'done'; $indicator = 'info'; $label = 'TURNED IN';
                    }
                }
            } else {
                if ($cw->deadline && $now->gt($cw->deadline)) {
                    $statusCode = 'missing'; $indicator = 'danger'; $label = 'MISSING';
                } else {
                    $statusCode = 'due_soon'; $indicator = 'primary'; $label = 'DUE SOON';
                }
            }

            $todos[] = [
                'id' => $cw->id,
                'title' => $cw->title,
                'classroom_id' => $cw->classroom_id,
                'subject_code' => $cw->classroom->subject->code ?? 'Class',
                'deadline' => $cw->deadline ? $cw->deadline->format('Y-m-d H:i') : null,
                'status_code' => $statusCode,
                'indicator' => $indicator,
                'label' => $label
            ];
        }

        // Pag-sort: Missing -> Returned -> Due Soon -> Late -> Done -> Graded
        usort($todos, function($a, $b) {
            $priority = [
                'missing' => 1, 
                'returned' => 2, 
                'due_soon' => 3, 
                'done_late' => 4, 
                'done' => 5, 
                'graded' => 6
            ];
            $pA = $priority[$a['status_code']] ?? 99;
            $pB = $priority[$b['status_code']] ?? 99;
            return $pA <=> $pB;
        });

        return response()->json([
            'user' => $student,
            'announcements' => $announcements,
            'classrooms_count' => $classroomsCount,
            'today_schedules' => $todaySchedules,
            'todos' => $todos
        ], 200);
    }

    // Post Comments
    public function postComment(Request $request, $announcementId)
    {
        $request->validate([
            'content' => 'required|string',
            'parent_id' => 'nullable' // Idinagdag para safe ang request
        ]);

        // KUNIN ANG ANNOUNCEMENT
        $announcement = Announcement::findOrFail($announcementId);
        
        // I-SAVE ANG COMMENT GAMIT ANG RELATIONSHIP (Para iwas bug sa Polymorphic mapping)
        $comment = $announcement->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $request->content,
            'parent_id' => $request->parent_id
        ]);

        // KUNIN ANG DETAILS PARA SA NOTIFICATION
        $currentUser = $request->user();
        $fullName = $currentUser->first_name . ' ' . $currentUser->last_name;
        $role = ucfirst($currentUser->role); 
        
        // Paiksiin ang text para magkasya nang maganda sa notification dropdown
        $snippet = Str::limit($request->content, 30);
        $announcementTitle = Str::limit($announcement->title, 25);

        // CHECK KUNG ITO BA AY REPLY O DIRECT COMMENT
        if ($request->parent_id) {
            // Hanapin kung kanino siya nag-reply
            $parentComment = Comment::with('user')->find($request->parent_id);
            $parentName = ($parentComment && $parentComment->user) 
                ? $parentComment->user->first_name . ' ' . $parentComment->user->last_name
                : 'someone';

            $description = "{$role}: {$fullName} replied to {$parentName} on '{$announcementTitle}': \"{$snippet}\"";
            $logAction = 'Replied to Announcement Comment';
            $logDescription = "Replied to a comment on the announcement '{$announcementTitle}'.";

            // NOTIFY THE TEACHER KUNG SA KANYA NI-REPLY
            if ($parentComment && $parentComment->user && $parentComment->user->role === 'teacher') {
                DB::table('notifications')->insert([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $parentComment->user->id,
                    'description' => "Student {$fullName} replied to your comment on '{$announcementTitle}': \"{$snippet}\"",
                    'link' => "/teacher/home", // Direkta sa teacher home/dashboard kung nasaan ang announcements
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

        } else {
            // Kung Direct Comment:
            $description = "{$role}: {$fullName} commented on '{$announcementTitle}': \"{$snippet}\"";
            $logAction = 'Commented on Announcement';
            $logDescription = "Added a comment on the announcement '{$announcementTitle}'.";
        }

        // NOTIFY ADMINS
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $admin->id,
                'description' => $description,
                'link' => "/admin/announcements", 
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ACTIVITY LOG 
        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => $logAction,
            'description' => $logDescription
        ]);

        return response()->json(['message' => 'Comment posted successfully', 'comment' => $comment], 201);
    }

    // Update Comments
    public function updateComment(Request $request, $id)
    {
        $request->validate(['content' => 'required|string']);
        $comment = Comment::where('user_id', $request->user()->id)->findOrFail($id);
        $comment->update(['content' => $request->content]);

        // ACTIVITY LOG 
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Updated Comment',
            'description' => "Updated a comment on a global announcement thread."
        ]);

        return response()->json(['message' => 'Comment updated successfully']);
    }

    // Delete Comments
    public function deleteComment(Request $request, $id)
    {
        $comment = Comment::where('user_id', $request->user()->id)->findOrFail($id);
        $comment->forceDelete();

        // ACTIVITY LOG 
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Deleted Comment',
            'description' => "Deleted a comment from a global announcement thread."
        ]);

        return response()->json(['message' => 'Comment deleted successfully']);
    }
}