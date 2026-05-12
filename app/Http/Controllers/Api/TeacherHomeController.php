<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Announcement;
use App\Models\ClassworkSubmission;
use App\Models\Comment;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeacherHomeController extends Controller
{
    // ROLE-BASED AUTHORIZATION HELPER
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // Home Page
    public function dashboard(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $teacher = $request->user();
            $now = now();

            // GET PUBLISHED ANNOUNCEMENTS
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

            // GET TO-DO LIST (Needs Grading)
            $todos = ClassworkSubmission::with(['classwork.classroom.subject', 'student'])
                ->whereHas('classwork.classroom', function($q) use ($teacher) {
                    $q->where('creator_id', $teacher->id);
                })
                ->whereIn('status', ['pending', 'late_submission'])
                ->whereNull('grade')
                ->whereNull('teacher_feedback') 
                ->orderBy('submitted_at', 'asc')
                ->get();

            // GET TOTAL CLASSROOMS
            $classroomsCount = Classroom::where('creator_id', $teacher->id)->count();

            // GET TODAY'S SCHEDULES
            $todayName = strtolower($now->format('l')); 
            $shortToday = strtolower($now->format('D')); 
            $todayDate = $now->format('Y-m-d');

            $todaySchedules = [];

            $deadlinesToday = Classwork::with('classroom.subject')
                ->whereHas('classroom', function($q) use ($teacher) {
                    $q->where('creator_id', $teacher->id);
                })->whereDate('deadline', $todayDate)->get();

            foreach ($deadlinesToday as $cw) {
                $todaySchedules[] = [
                    'id' => 'cw_' . $cw->id,
                    'classroom_id' => $cw->classroom_id, 
                    'title' => 'Deadline: ' . $cw->title,
                    'type' => 'deadline',
                    'time' => $cw->deadline->format('h:i A')
                ];
            }

            $classrooms = Classroom::with('subject')->where('creator_id', $teacher->id)->get();
            foreach ($classrooms as $room) {
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

            return response()->json([
                'user' => $teacher,
                'announcements' => $announcements,
                'todos' => $todos,
                'classrooms_count' => $classroomsCount,
                'today_schedules' => $todaySchedules
            ], 200);
        } catch (\Exception $e) {
            Log::error('Teacher Dashboard Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while loading the dashboard.'], 500);
        }
    }

    // Create Comment
    public function postComment(Request $request, $announcementId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate([
                'content' => 'required|string',
                'parent_id' => 'nullable' // para safe ang request
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
                // Kung Reply: Hanapin kung kanino siya nag-reply
                $parentComment = Comment::with('user')->find($request->parent_id);
                $parentName = ($parentComment && $parentComment->user) 
                    ? $parentComment->user->first_name . ' ' . $parentComment->user->last_name
                    : 'someone';

                $description = "{$role}: {$fullName} replied to {$parentName} on '{$announcementTitle}': \"{$snippet}\"";
                $logAction = 'Replied to Announcement Comment';
                $logDescription = "Replied to a comment on the announcement '{$announcementTitle}'.";
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
        } catch (\Exception $e) {
            Log::error('Post Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while posting your comment.'], 500);
        }
    }

    // Update Comment
    public function updateComment(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
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
        } catch (\Exception $e) {
            Log::error('Update Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while updating the comment.'], 500);
        }
    }

    // Delete Comment
    public function deleteComment(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $comment = Comment::where('user_id', $request->user()->id)->findOrFail($id);
            $comment->forceDelete();

            // ACTIVITY LOG 
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Comment',
                'description' => "Deleted a comment from a global announcement thread."
            ]);

            return response()->json(['message' => 'Comment deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Delete Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while deleting the comment.'], 500);
        }
    }
}