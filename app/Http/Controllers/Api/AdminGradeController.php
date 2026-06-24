<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\FinalGrade;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AdminGradeController extends Controller
{
    // security
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // Student Records
    public function index(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = User::where('role', 'student')
                ->where('status', 'active')
                ->with('strand')
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('final_grades')
                      ->whereColumn('final_grades.student_id', 'users.id');
                });

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$search}%")
                      ->orWhere('lrn', 'LIKE', "%{$search}%");
                });
            }

            if ($request->has('strand') && $request->strand !== 'all') {
                $query->where('strand_id', $request->strand);
            }

            if ($request->has('gender') && $request->gender !== 'all') {
                $query->where('gender', $request->gender);
            }

            $sortOrder = $request->has('sort') && $request->sort === 'za' ? 'desc' : 'asc';
            $query->orderBy('last_name', $sortOrder)->orderBy('first_name', $sortOrder);
            $entries = $request->has('entries') ? (int) $request->entries : 10;
            $paginatedStudents = $query->paginate($entries);

            foreach ($paginatedStudents->items() as $student) {
                $student->has_pending_grades = FinalGrade::where('student_id', $student->id)
                                                ->where('status', 'pending')
                                                ->exists();
                $student->grades_count = FinalGrade::where('student_id', $student->id)->count();
            }

            return response()->json([
                'data' => $paginatedStudents->items(),
                'total' => $paginatedStudents->total(),
                'last_page' => $paginatedStudents->lastPage()
            ], 200);

        } catch (\Exception $e) {
            Log::error('AdminGradeController index Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching student grades.'], 500);
        }
    }

    // Show Grades
    public function showStudentGrades(Request $request, $studentId)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $grades = DB::table('final_grades')
                ->join('subjects', 'final_grades.subject_id', '=', 'subjects.id')
                ->join('users', 'final_grades.teacher_id', '=', 'users.id') 
                ->where('final_grades.student_id', $studentId)
                ->whereNull('final_grades.deleted_at')
                ->select(
                    'final_grades.*', 
                    'subjects.code as subject_code', 
                    'subjects.description as subject_description',
                    DB::raw("CONCAT(users.first_name, ' ', users.last_name) as teacher_name") 
                )
                ->orderBy('final_grades.school_year', 'desc')
                ->orderBy('final_grades.term', 'asc')
                ->get();

            return response()->json($grades, 200);

        } catch (\Exception $e) {
            Log::error('AdminGradeController showStudentGrades Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching specific grades.'], 500);
        }
    }

    // Single Approve Grade
    public function approveGrade(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate(['grade_id' => 'required|uuid']);
            DB::beginTransaction();

            $grade = FinalGrade::findOrFail($request->grade_id);
            $grade->update(['status' => 'approved', 'admin_feedback' => null]);
            $this->logAndNotifyApproval($grade, $request->user()->id);

            DB::commit();
            return response()->json(['message' => 'Grade approved and locked.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminGradeController approveGrade Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while approving the grade.'], 500);
        }
    }

    // BULK Approve Grades
    public function bulkApproveGrades(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate(['grade_ids' => 'required|array']);
            DB::beginTransaction();

            $grades = FinalGrade::whereIn('id', $request->grade_ids)->get();
            $count = 0;

            foreach ($grades as $grade) {
                if ($grade->status !== 'approved') {
                    $grade->update(['status' => 'approved', 'admin_feedback' => null]);
                    $this->logAndNotifyApproval($grade, $request->user()->id);
                    $count++;
                }
            }

            DB::commit();
            return response()->json(['message' => "{$count} grades successfully approved and locked."], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminGradeController bulkApproveGrades Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while bulk approving.'], 500);
        }
    }

    // Helper for Approval Notifications
    private function logAndNotifyApproval($grade, $adminId)
    {
        $student = User::find($grade->student_id);
        $subject = DB::table('subjects')->where('id', $grade->subject_id)->first();
        $subjectName = $subject ? $subject->description : 'Subject';

        $advisoryClass = DB::table('advisory_classes')
            ->join('advisory_student', 'advisory_classes.id', '=', 'advisory_student.advisory_class_id')
            ->where('advisory_student.student_id', $grade->student_id)
            ->where('advisory_classes.teacher_id', $grade->teacher_id)
            ->where('advisory_classes.school_year', $grade->school_year)
            ->whereNull('advisory_classes.deleted_at')
            ->select('advisory_classes.id as class_id', 'advisory_classes.section')
            ->first();
            
        $sectionName = $advisoryClass ? "({$advisoryClass->section})" : "";
        $link = $advisoryClass ? "/teacher/advisory/{$advisoryClass->class_id}" : "/teacher/advisory";
        $currentTime = now()->toDateTimeString();
        $term = $grade->term; 

        DB::table('notifications')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $grade->teacher_id,
            'description' => "Your submitted {$subjectName} grade for {$student->first_name} {$student->last_name} {$sectionName} ({$term} Term) was approved.",
            'link' => $link, 
            'is_read' => false,
            'created_at' => $currentTime, 
            'updated_at' => $currentTime,
        ]);

        DB::table('notifications')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $grade->student_id,
            'description' => "Your final grade in {$subjectName} for SY {$grade->school_year} ({$term} Term) is now ready to view.",
            'link' => "/student/grades", 
            'is_read' => false,
            'created_at' => $currentTime, 
            'updated_at' => $currentTime,
        ]);

        ActivityLog::create([
            'user_id' => $adminId,
            'action' => 'Approved Student Grade',
            'description' => "Approved the {$subjectName} grade of {$student->first_name} {$student->last_name} for SY {$grade->school_year}."
        ]);
    }

    // Single Decline Grades
    public function declineGrade(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate(['grade_id' => 'required|uuid', 'feedback' => 'required|string']);
            DB::beginTransaction();

            $grade = FinalGrade::findOrFail($request->grade_id);
            $grade->update(['status' => 'declined', 'admin_feedback' => $request->feedback]);
            $this->logAndNotifyDecline($grade, $request->feedback, $request->user()->id);

            DB::commit();
            return response()->json(['message' => 'Grade declined and returned to teacher.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminGradeController declineGrade Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while declining the grade.'], 500);
        }
    }

    // BULK Decline Grades
    public function bulkDeclineGrades(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate(['grade_ids' => 'required|array', 'feedback' => 'required|string']);
            DB::beginTransaction();

            $grades = FinalGrade::whereIn('id', $request->grade_ids)->get();
            $count = 0;

            foreach ($grades as $grade) {
                $grade->update(['status' => 'declined', 'admin_feedback' => $request->feedback]);
                $this->logAndNotifyDecline($grade, $request->feedback, $request->user()->id);
                $count++;
            }

            DB::commit();
            return response()->json(['message' => "{$count} grades successfully declined and returned to teacher."], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminGradeController bulkDeclineGrades Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while bulk declining.'], 500);
        }
    }

    // Helper for Decline Notifications
    private function logAndNotifyDecline($grade, $feedback, $adminId)
    {
        $student = User::find($grade->student_id);
        $subject = DB::table('subjects')->where('id', $grade->subject_id)->first();
        $subjectName = $subject ? $subject->description : 'Subject';

        $advisoryClass = DB::table('advisory_classes')
            ->join('advisory_student', 'advisory_classes.id', '=', 'advisory_student.advisory_class_id')
            ->where('advisory_student.student_id', $grade->student_id)
            ->where('advisory_classes.teacher_id', $grade->teacher_id)
            ->where('advisory_classes.school_year', $grade->school_year)
            ->whereNull('advisory_classes.deleted_at')
            ->select('advisory_classes.id as class_id', 'advisory_classes.section')
            ->first();

        $sectionName = $advisoryClass ? "({$advisoryClass->section})" : "";
        $link = $advisoryClass ? "/teacher/advisory/{$advisoryClass->class_id}" : "/teacher/advisory";

        DB::table('notifications')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $grade->teacher_id,
            'description' => "Your submitted grade for {$student->first_name} {$student->last_name} {$sectionName} was declined. Feedback: " . Str::limit($feedback, 30),
            'link' => $link, 
            'is_read' => false,
            'created_at' => now(), 
            'updated_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => $adminId,
            'action' => 'Declined Student Grade',
            'description' => "Declined the {$subjectName} grade of {$student->first_name} {$student->last_name} and provided feedback."
        ]);
    }

    // bulk and single deletion
    public function bulkDeleteGrades(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate(['grade_ids' => 'required|array']);
            DB::beginTransaction();

            $count = FinalGrade::whereIn('id', $request->grade_ids)
                               ->where('status', 'approved')
                               ->forceDelete();

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Bulk Deleted Student Grades',
                    'description' => "Permanently deleted {$count} approved final grade records."
                ]);
            }

            DB::commit();
            return response()->json(['message' => "{$count} approved grades successfully deleted."], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminGradeController bulkDeleteGrades Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting.'], 500);
        }
    }
}