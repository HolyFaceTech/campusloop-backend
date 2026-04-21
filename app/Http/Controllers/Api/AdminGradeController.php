<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\FinalGrade;
use App\Models\ActivityLog;
use Illuminate\Support\Str;

class AdminGradeController extends Controller
{
    // KUNIN LAHAT NG STUDENTS NA MAY GRADES RECORD
    public function index(Request $request)
    {
        try {
            // Kunin lang ang mga students (role='student')
            $students = User::where('role', 'student')
                ->where('status', 'active')
                ->with('strand')
                ->get();

            // Indicator kung may "pending" grades
            foreach ($students as $student) {
                $student->has_pending_grades = FinalGrade::where('student_id', $student->id)
                                                ->where('status', 'pending')
                                                ->exists();
                // Bilangin din ilang grades meron overall
                $student->grades_count = FinalGrade::where('student_id', $student->id)->count();
            }

            // I-filter out yung mga walang grades para malinis ang listahan
            $studentsWithGrades = $students->filter(function($student) {
                return $student->grades_count > 0;
            })->values();

            return response()->json($studentsWithGrades, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }

    // KUNIN ANG SPECIFIC GRADES NG ISANG STUDENT (For the Modal Table)
    public function showStudentGrades($studentId)
    {
        try {
            $grades = DB::table('final_grades')
                ->join('subjects', 'final_grades.subject_id', '=', 'subjects.id')
                ->join('users', 'final_grades.teacher_id', '=', 'users.id') // JOIN PARA SA TEACHER
                ->where('final_grades.student_id', $studentId)
                ->whereNull('final_grades.deleted_at')
                ->select(
                    'final_grades.*', 
                    'subjects.code as subject_code', 
                    'subjects.description as subject_description',
                    DB::raw("CONCAT(users.first_name, ' ', users.last_name) as teacher_name") // KUNIN ANG PANGALAN
                )
                ->orderBy('final_grades.school_year', 'desc')
                ->orderBy('final_grades.semester', 'asc')
                ->get();

            return response()->json($grades, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }

    // Approved Grades
    public function approveGrade(Request $request)
    {
        try {
            $request->validate(['grade_id' => 'required|uuid']);
            
            // Pinalitan natin ng Eloquent Model at findOrFail para mawala ang red lines
            $grade = FinalGrade::findOrFail($request->grade_id);
            
            $grade->update([
                'status' => 'approved',
                'admin_feedback' => null, 
            ]);

            $student = User::findOrFail($grade->student_id);

            // KUNIN ANG SUBJECT DESCRIPTION
            $subject = DB::table('subjects')->where('id', $grade->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Subject';

            // THE FOOLPROOF QUERY FOR NOTIFICATIONS
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
            $semester = $grade->semester; 

            // Notify teacher (Approved)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $grade->teacher_id,
                'description' => "Your submitted {$subjectName} grade for {$student->first_name} {$student->last_name} {$sectionName} ({$semester} Semester) was approved.",
                'link' => $link, 
                'is_read' => false,
                'created_at' => $currentTime, 
                'updated_at' => $currentTime,
            ]);

            // Notify student (Approved)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $grade->student_id,
                'description' => "Your final grade in {$subjectName} for SY {$grade->school_year} ({$semester} Semester) is now ready to view.",
                'link' => "/student/grades", 
                'is_read' => false,
                'created_at' => $currentTime, 
                'updated_at' => $currentTime,
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Approved Student Grade',
                'description' => "Approved the {$subjectName} grade of {$student->first_name} {$student->last_name} for SY {$grade->school_year}."
            ]);

            return response()->json(['message' => 'Grade approved and locked.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }

    // Declined Grades
    public function declineGrade(Request $request)
    {
        try {
            $request->validate([
                'grade_id' => 'required|uuid',
                'feedback' => 'required|string'
            ]);
            
            // Pinalitan natin ng Eloquent Model at findOrFail para mawala ang red lines
            $grade = FinalGrade::findOrFail($request->grade_id);
            
            $grade->update([
                'status' => 'declined',
                'admin_feedback' => $request->feedback,
            ]);

            $student = User::findOrFail($grade->student_id);

            $subject = DB::table('subjects')->where('id', $grade->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Subject';

            // THE FOOLPROOF QUERY FOR NOTIFICATIONS
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

            // Notify teacher (Declined)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $grade->teacher_id,
                'description' => "Your submitted grade for {$student->first_name} {$student->last_name} {$sectionName} was declined. Feedback: " . Str::limit($request->feedback, 30),
                'link' => $link, 
                'is_read' => false,
                'created_at' => now(), 
                'updated_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Declined Student Grade',
                'description' => "Declined the {$subjectName} grade of {$student->first_name} {$student->last_name} and provided feedback."
            ]);

            return response()->json(['message' => 'Grade declined and returned to teacher.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }
}