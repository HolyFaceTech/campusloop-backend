<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Comment;
use App\Models\Classwork;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Str;

class CommentController extends Controller
{
    // Post Comment and Reply
    public function store(Request $request, $classworkId)
    {
        try {
            $request->validate([
                'content' => 'required|string',
                'parent_id' => 'nullable|exists:comments,id'
            ]);

            $classwork = Classwork::findOrFail($classworkId);
            $classroom = Classroom::findOrFail($classwork->classroom_id);
            $currentUser = Auth::user();

            // Broken Access Control (Missing Enrollment Check)
            // Siguraduhing legitimate member ng klase ang nagko-comment
            if ($currentUser->role === 'teacher') {
                if ($classroom->creator_id !== $currentUser->id) {
                    return response()->json(['message' => 'Unauthorized. You are not the teacher of this class.'], 403);
                }
            } elseif ($currentUser->role === 'student') {
                $isEnrolled = DB::table('classroom_student')
                    ->where('classroom_id', $classroom->id)
                    ->where('student_id', $currentUser->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isEnrolled) {
                    return response()->json(['message' => 'Unauthorized. You are not an approved student in this class.'], 403);
                }
            } else {
                return response()->json(['message' => 'Unauthorized Access.'], 403);
            }

            // Prevent replying to a comment from a different classwork
            if ($request->parent_id) {
                $parentCheck = Comment::findOrFail($request->parent_id);
                if ((int)$parentCheck->commentable_id !== (int)$classworkId) {
                    return response()->json(['message' => 'Invalid parent comment reference.'], 400);
                }
            }

            // I-SAVE ANG COMMENT SA DATABASE
            $comment = Comment::create([
                'user_id' => $currentUser->id,
                'commentable_type' => Classwork::class,
                'commentable_id' => $classworkId,
                'parent_id' => $request->parent_id,
                'content' => $request->content
            ]);

            // KUNIN ANG SUBJECT AT SECTION
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';
            $sectionName = $classroom->section;

            // FORMAT DETAILS PARA SA DROPDOWN
            $fullName = $currentUser->first_name . ' ' . $currentUser->last_name;
            $role = ucfirst($currentUser->role); 
            
            $snippet = Str::limit($request->content, 30);
            $classworkTitle = Str::limit($classwork->title, 25);

            // CHECK KUNG REPLY O DIRECT COMMENT
            $parentComment = null;
            if ($request->parent_id) {
                $parentComment = Comment::with('user')->find($request->parent_id);
                $parentName = ($parentComment && $parentComment->user) 
                    ? $parentComment->user->first_name . ' ' . $parentComment->user->last_name
                    : 'someone';

                $description = "{$role} {$fullName} replied to {$parentName} on '{$classworkTitle}' in {$subjectName} ({$sectionName}): \"{$snippet}\"";
            } else {
                $description = "{$role} {$fullName} commented on '{$classworkTitle}' in {$subjectName} ({$sectionName}): \"{$snippet}\"";
            }

            // NOTIFICATION LOGIC 
            $currentTime = now()->toDateTimeString();
            $notifications = [];

            // 1. NOTIFY TEACHER
            if ($currentUser->id !== $classroom->creator_id) {
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $classroom->creator_id,
                    'description' => $description,
                    'link' => "/teacher/classrooms/{$classroom->id}/stream",
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }

            // NOTIFY STUDENTS
            if ($request->parent_id) {
                if ($parentComment && $parentComment->user_id !== $currentUser->id) {
                    $targetUser = $parentComment->user;
                    if ($targetUser && $targetUser->role === 'student') {
                        $notifications[] = [
                            'id' => Str::uuid()->toString(),
                            'user_id' => $targetUser->id,
                            'description' => $description,
                            'link' => "/student/classrooms/{$classroom->id}/stream",
                            'is_read' => false,
                            'created_at' => $currentTime,
                            'updated_at' => $currentTime,
                        ];
                    }
                }
            } else {
                if ($currentUser->id === $classroom->creator_id) {
                    $approvedStudents = $classroom->students()->wherePivot('status', 'approved')->get();
                    foreach ($approvedStudents as $student) {
                        $notifications[] = [
                            'id' => Str::uuid()->toString(),
                            'user_id' => $student->id,
                            'description' => $description,
                            'link' => "/student/classrooms/{$classroom->id}/stream",
                            'is_read' => false,
                            'created_at' => $currentTime,
                            'updated_at' => $currentTime,
                        ];
                    }
                } else {
                    $participantIds = Comment::where('commentable_id', $classworkId)
                        ->where('commentable_type', Classwork::class)
                        ->where('user_id', '!=', $currentUser->id)
                        ->where('user_id', '!=', $classroom->creator_id)
                        ->pluck('user_id')
                        ->unique();

                    if ($participantIds->isNotEmpty()) {
                        $participants = User::whereIn('id', $participantIds)->where('role', 'student')->get();
                        foreach ($participants as $pUser) {
                            $notifications[] = [
                                'id' => Str::uuid()->toString(),
                                'user_id' => $pUser->id,
                                'description' => $description,
                                'link' => "/student/classrooms/{$classroom->id}/stream",
                                'is_read' => false,
                                'created_at' => $currentTime,
                                'updated_at' => $currentTime,
                            ];
                        }
                    }
                }
            }

            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            return response()->json(['message' => 'Comment posted successfully!', 'comment' => $comment], 201);
        } catch (\Exception $e) {
            // Information Leakage sa Error Handling
            Log::error('Store Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while processing your request.'], 500);
        }
    }

    // UPDATE COMMENT
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'content' => 'required|string'
            ]);

            $comment = Comment::findOrFail($id);

            if ($comment->user_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized to edit this comment.'], 403);
            }

            $comment->update([
                'content' => $request->content
            ]);

            return response()->json(['message' => 'Comment updated successfully!', 'comment' => $comment], 200);
        } catch (\Exception $e) {
            // Information Leakage
            Log::error('Update Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while processing your request.'], 500);
        }
    }

    // DELETE COMMENT
    public function destroy($id)
    {
        try {
            $comment = Comment::findOrFail($id);

            if ($comment->user_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized to delete this comment.'], 403);
            }

            Comment::where('parent_id', $comment->id)->delete();
            $comment->delete();

            return response()->json(['message' => 'Comment deleted successfully!'], 200);
        } catch (\Exception $e) {
            // Information Leakage
            Log::error('Delete Comment Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while processing your request.'], 500);
        }
    }
}