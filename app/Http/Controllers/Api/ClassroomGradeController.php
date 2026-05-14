<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClassroomGradeController extends Controller
{
    // access role
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // view classroom records
    public function index(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $classroom = DB::table('classrooms')
                ->where('id', $classroomId)
                ->where('creator_id', Auth::id())
                ->first();

            if (!$classroom) {
                return response()->json(['message' => 'Classroom not found or unauthorized'], 404);
            }

            $search = $request->input('search');
            $entries = $request->input('entries', 10);

            $classworks = DB::table('classworks')
                ->where('classroom_id', $classroomId)
                ->where('type', '!=', 'material') 
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'asc') 
                ->select('id', 'title', 'type', 'points', 'created_at', 'deadline')
                ->get();

            $studentQuery = DB::table('classroom_student')
                ->join('users', 'classroom_student.student_id', '=', 'users.id')
                ->where('classroom_student.classroom_id', $classroomId)
                ->where('classroom_student.status', 'approved')
                ->whereNull('classroom_student.deleted_at')
                ->select('users.id', 'users.first_name', 'users.last_name', 'users.lrn');

            if (!empty($search)) {
                $studentQuery->where(function($q) use ($search) {
                    $q->where('users.first_name', 'like', "%{$search}%")
                      ->orWhere('users.last_name', 'like', "%{$search}%")
                      ->orWhere('users.lrn', 'like', "%{$search}%");
                });
            }

            $paginatedStudents = $studentQuery->orderBy('users.last_name', 'asc')->paginate($entries);
            $studentIds = collect($paginatedStudents->items())->pluck('id')->toArray();
            $classworkIds = $classworks->pluck('id')->toArray();
            $submissions = [];
            
            if (!empty($classworkIds) && !empty($studentIds)) {
                $submissionsData = DB::table('classwork_submissions')
                    ->whereIn('classwork_id', $classworkIds)
                    ->whereIn('student_id', $studentIds)
                    ->select('classwork_id', 'student_id', 'status', 'grade', 'teacher_feedback', 'submitted_at')
                    ->get();
                    
                foreach ($submissionsData as $sub) {
                    $submissions[$sub->student_id][] = (array) $sub;
                }
            }

            $studentsData = [];
            $now = now();

            foreach ($paginatedStudents->items() as $student) {
                $studentSubs = $submissions[$student->id] ?? [];
                $processedSubs = [];

                foreach ($classworks as $cw) {
                    $sub = collect($studentSubs)->firstWhere('classwork_id', $cw->id);
                    
                    if ($sub) {
                        $processedSubs[] = $sub;
                    } else {
                        if ($cw->deadline && $now > $cw->deadline) {
                            $processedSubs[] = [
                                'classwork_id' => $cw->id,
                                'student_id' => $student->id,
                                'status' => 'missing',
                                'grade' => null,
                                'teacher_feedback' => null
                            ];
                        }
                    }
                }

                $studentsData[] = [
                    'id' => $student->id,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'lrn' => $student->lrn,
                    'submissions' => $processedSubs
                ];
            }

            return response()->json([
                'classworks' => $classworks,
                'students' => $studentsData,
                'total' => $paginatedStudents->total(),
                'last_page' => $paginatedStudents->lastPage()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Grades Fetch Error: ' . $e->getMessage() . ' on line ' . $e->getLine());
            return response()->json([
                'message' => 'An unexpected error occurred while fetching the class record.'
            ], 500);
        }
    }
}