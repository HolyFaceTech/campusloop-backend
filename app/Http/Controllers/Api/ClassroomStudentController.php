<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 

class ClassroomStudentController extends Controller
{
    // security
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // view students
    public function index(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $classroom = Classroom::where('creator_id', $request->user()->id)->findOrFail($classroomId);
            $search = $request->input('search');
            $gender = $request->input('gender', 'all');
            $status = $request->input('status', 'all');
            $entries = $request->input('entries', 10);
            $query = $classroom->students()->with('strand');

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('lrn', 'like', "%{$search}%");
                });
            }

            if ($gender !== 'all') {
                $query->where('gender', $gender);
            }

            if ($status !== 'all') {
                $query->wherePivot('status', $status);
            }

            $students = $query->orderBy('last_name', 'asc')->paginate($entries);

            return response()->json($students, 200);

        } catch (\Exception $e) {
            Log::error('Fetch Classroom Students Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Unauthorized or Classroom not found.'], 403);
        }
    }

    // APPROVE STUDENT REQUEST
    public function approve(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        $request->validate([
            'student_ids' => 'required|array|max:100',
            'student_ids.*' => 'exists:users,id'
        ]);
        
        try {
            DB::beginTransaction();

            $classroom = Classroom::with('subject')
                ->where('creator_id', $request->user()->id)
                ->lockForUpdate()
                ->findOrFail($classroomId);

            $currentApprovedCount = DB::table('classroom_student')
                ->where('classroom_id', $classroomId)
                ->where('status', 'approved')
                ->count();

            $requestedCount = count($request->student_ids);
            $availableSlots = $classroom->capacity - $currentApprovedCount; 

            if ($requestedCount > $availableSlots) {
                DB::rollBack();
                return response()->json([
                    'message' => "Approval failed. The classroom only has {$availableSlots} slot(s) remaining, but you are trying to approve {$requestedCount} student(s)."
                ], 422);
            }

            foreach ($request->student_ids as $studentId) {
                $classroom->students()->updateExistingPivot($studentId, ['status' => 'approved']);
            }

            $teacherName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $subjectName = $classroom->subject ? $classroom->subject->description : 'the class';
            $sectionName = $classroom->section;
            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach ($request->student_ids as $studentId) {
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $studentId,
                    'description' => "Teacher {$teacherName} approved your request to join {$subjectName} ({$sectionName}).",
                    'link' => "/student/classrooms/{$classroomId}", 
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }

            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $count = count($request->student_ids);

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Approved Classroom Students',
                    'description' => "Approved {$count} student(s) to join the classroom {$subjectName} ({$sectionName})."
                ]);
            }

            DB::commit(); 
            return response()->json(['message' => 'Students successfully enrolled.'], 200);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('Approve Student Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while approving students.'], 500);
        }
    }

    // REMOVE OR DECLINE STUDENT
    public function remove(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        $request->validate([
            'student_ids' => 'required|array|max:100',
            'student_ids.*' => 'exists:users,id'
        ]);

        try {
            $classroom = Classroom::with('subject')->where('creator_id', $request->user()->id)->findOrFail($classroomId);
            DB::beginTransaction();
            $studentsToNotify = $classroom->students()->whereIn('student_id', $request->student_ids)->get();
            $classroom->students()->detach($request->student_ids);
            $teacherName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $subjectName = $classroom->subject ? $classroom->subject->description : 'the class';
            $sectionName = $classroom->section;
            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach ($studentsToNotify as $student) {
                $previousStatus = $student->pivot->status;

                if ($previousStatus === 'approved') {
                    $description = "Teacher {$teacherName} unenrolled you from {$subjectName} ({$sectionName}).";
                } else {
                    $description = "Teacher {$teacherName} declined your request to join {$subjectName} ({$sectionName}).";
                }

                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $student->id,
                    'description' => $description,
                    'link' => "/student/classrooms", 
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }

            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $count = count($request->student_ids);

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Removed/Declined Classroom Students',
                    'description' => "Removed or declined {$count} student(s) from the classroom {$subjectName} ({$sectionName})."
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Students successfully removed from class.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Remove Student Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while processing removal.'], 500);
        }
    }
}