<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdvisoryClass;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdvisoryClassController extends Controller
{
    // SAFE ROLE-BASED AUTHORIZATION HELPER
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // view enrolled students
    public function index(Request $request)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $search = $request->input('search');
            $entries = $request->input('entries', 10);

            $query = AdvisoryClass::where('teacher_id', $request->user()->id);

            // SERVER-SIDE SEARCH LOGIC
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('section', 'like', '%' . $search . '%')
                      ->orWhere('school_year', 'like', '%' . $search . '%');
                });
            }

            // PAGINATION
            $classes = $query->orderBy('created_at', 'desc')->paginate($entries);

            // APPEND STUDENTS COUNT SA BAWAT CLASS
            foreach($classes as $class) {
                $class->students_count = DB::table('advisory_student')
                    ->join('users', 'advisory_student.student_id', '=', 'users.id')
                    ->where('advisory_student.advisory_class_id', $class->id)
                    ->whereNull('users.deleted_at')
                    ->count();
            }

            return response()->json([
                'data' => $classes->items(),
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'total' => $classes->total()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Fetch Advisory Classes Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching classes.'], 500);
        }
    }

    // Create Advisory Class
    public function store(Request $request)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate([
                'section' => 'required|string|max:255',
                'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
                'capacity' => 'required|integer|min:1',
            ]);

            $advisory = AdvisoryClass::create([
                'teacher_id' => $request->user()->id,
                'section' => $request->section,
                'school_year' => $request->school_year,
                'capacity' => $request->capacity,
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created Advisory Class',
                'description' => "Created a new advisory class: {$request->section} for SY {$request->school_year}."
            ]);

            return response()->json(['message' => 'Created successfully.', 'advisory' => $advisory], 201);
        } catch (\Exception $e) {
            Log::error('Create Advisory Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while creating the class.'], 500);
        }
    }

    // Update Advisory Class
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate([
                'section' => 'required|string|max:255',
                'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
                'capacity' => 'required|integer|min:1',
            ]);

            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($id);
            $advisory->update($request->only('section', 'school_year', 'capacity'));

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Advisory Class',
                'description' => "Updated the details of the advisory class: {$request->section}."
            ]);

            return response()->json(['message' => 'Updated successfully.', 'advisory' => $advisory], 200);
        } catch (\Exception $e) {
            Log::error('Update Advisory Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while updating the class.'], 500);
        }
    }

    // delete advisory
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($id);
            $section = $advisory->section;

            $advisory->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Advisory Class',
                'description' => "Moved the advisory class {$section} to the recycle bin."
            ]);
            return response()->json(['message' => 'Moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            Log::error('Delete Advisory Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting the class.'], 500);
        }
    }

    // View Advisory Class 
    public function show(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $search = $request->input('search');
            $entries = $request->input('entries', 10);

            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($id);
            $enrolledIds = DB::table('advisory_student')
                ->join('users', 'advisory_student.student_id', '=', 'users.id')
                ->where('advisory_student.advisory_class_id', $id)
                ->whereNull('users.deleted_at')
                ->pluck('advisory_student.student_id');
            
            $query = User::whereIn('id', $enrolledIds)->where('role', 'student')->with('strand');

            // SERVER-SIDE SEARCH LOGIC PARA SA MGA ESTUDYANTE
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', '%' . $search . '%')
                      ->orWhere('last_name', 'like', '%' . $search . '%')
                      ->orWhere('email', 'like', '%' . $search . '%')
                      ->orWhere('lrn', 'like', '%' . $search . '%');
                });
            }

            // SMART PAGINATION
            $students = $query->orderBy('last_name', 'asc')->paginate($entries);

            return response()->json([
                'advisory' => $advisory, 
                'students' => $students->items(),
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'total' => $students->total(),
                'total_enrolled' => count($enrolledIds)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Show Advisory Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Available Student 
    public function getAvailableStudents(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $search = $request->input('search');
            $entries = $request->input('entries', 10);
            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($id);
            $enrolledIds = DB::table('advisory_student')->where('advisory_class_id', $id)->pluck('student_id');
            
            $query = User::where('role', 'student')
                         ->where('status', 'active')
                         ->whereNull('deleted_at') 
                         ->whereNotIn('id', $enrolledIds)
                         ->with('strand');

            // SERVER-SIDE SEARCH LOGIC
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', '%' . $search . '%')
                      ->orWhere('last_name', 'like', '%' . $search . '%')
                      ->orWhere('lrn', 'like', '%' . $search . '%');
                });
            }

            // PAGINATION
            $available = $query->orderBy('last_name', 'asc')->paginate($entries);

            return response()->json([
                'data' => $available->items(),
                'current_page' => $available->currentPage(),
                'last_page' => $available->lastPage(),
                'total' => $available->total()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get Available Students Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Enroll Student in Advisory Class
    public function addStudents(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate(['student_ids' => 'required|array|max:100', 'student_ids.*' => 'exists:users,id']);
            
            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($id);
            
            $currentCount = DB::table('advisory_student')
                ->join('users', 'advisory_student.student_id', '=', 'users.id')
                ->where('advisory_student.advisory_class_id', $id)
                ->whereNull('users.deleted_at')
                ->count();
            $incomingCount = count($request->student_ids);
            
            if (($currentCount + $incomingCount) > $advisory->capacity) {
                $available = $advisory->capacity - $currentCount;
                return response()->json([
                    'message' => "Cannot add students. Only {$available} slot(s) remaining."
                ], 422); 
            }

            $inserts = [];
            foreach ($request->student_ids as $studentId) {
                $exists = DB::table('advisory_student')->where('advisory_class_id', $id)->where('student_id', $studentId)->exists();
                if (!$exists) {
                    $inserts[] = [
                        'id' => Str::uuid()->toString(),
                        'advisory_class_id' => $id,
                        'student_id' => $studentId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }
            if(count($inserts) > 0) {
                DB::table('advisory_student')->insert($inserts);

                $enrolledCount = count($inserts);

                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Enrolled Advisory Students',
                    'description' => "Enrolled {$enrolledCount} student(s) to the advisory class {$advisory->section}."
                ]);
            }
            return response()->json(['message' => 'Students added.'], 200);
        } catch (\Exception $e) {
            Log::error('Add Students Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // remove student
    public function removeStudent(Request $request, $classId, $studentId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($classId);
            $student = User::find($studentId);
            $studentName = $student ? ($student->first_name . ' ' . $student->last_name) : 'a student';

            DB::table('advisory_student')->where('advisory_class_id', $classId)->where('student_id', $studentId)->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Removed Advisory Student',
                'description' => "Removed {$studentName} from the advisory class {$advisory->section}."
            ]);

            return response()->json(['message' => 'Student removed.'], 200);
        } catch (\Exception $e) {
            Log::error('Remove Student Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // View Student Grade
    public function getStudentGrades(Request $request, $classId, $studentId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($classId);
            $student = User::findOrFail($studentId); 

            $search = $request->input('search');
            $entries = $request->input('entries', 10);
            $syFilter = $request->input('syFilter', 'all');
            $semFilter = $request->input('semFilter', 'all');

            $query = DB::table('final_grades')
                ->join('subjects', 'final_grades.subject_id', '=', 'subjects.id')
                ->where('final_grades.student_id', $studentId)
                ->whereNull('final_grades.deleted_at')
                ->select(
                    'final_grades.*', 
                    'subjects.code as subject_code', 
                    'subjects.description as subject_description' 
                );

            if ($syFilter !== 'all') {
                $query->where('final_grades.school_year', $syFilter);
            } else {
                $query->where('final_grades.school_year', $advisory->school_year);
            }

            if ($semFilter !== 'all') {
                $query->where('final_grades.semester', $semFilter);
            }

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('subjects.code', 'like', '%' . $search . '%')
                      ->orWhere('subjects.description', 'like', '%' . $search . '%');
                });
            }

            $grades = $query->orderBy('final_grades.created_at', 'desc')->paginate($entries);

            $encodedSubjectIds = DB::table('final_grades')
                ->where('student_id', $studentId)
                ->where('school_year', $advisory->school_year)
                ->whereNull('deleted_at')
                ->pluck('subject_id');

            $allowedSubjects = DB::table('subjects')
                ->leftJoin('strands', 'subjects.strand_id', '=', 'strands.id')
                ->select('subjects.*', 'strands.name as strand_name')
                ->whereNull('subjects.deleted_at') 
                ->where(function($q) use ($student) {
                    $q->whereNull('subjects.strand_id')
                      ->orWhere('subjects.strand_id', $student->strand_id);
                })
                ->orderBy('subjects.code', 'asc')
                ->get();
                
            return response()->json([
                'data' => $grades->items(),
                'current_page' => $grades->currentPage(),
                'last_page' => $grades->lastPage(),
                'total' => $grades->total(),
                'encoded_subject_ids' => $encodedSubjectIds,
                'unique_sys' => [$advisory->school_year],
                'allowed_subjects' => $allowedSubjects 
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get Student Grades Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Input Student Grade
    public function storeStudentGrade(Request $request, $classId, $studentId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate([
                'subject_id' => 'required',
                'semester' => 'required',
                'grade' => ['required', 'numeric', 'between:1,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            ]);

            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($classId);
            $gradeId = Str::uuid()->toString();

            DB::table('final_grades')->insert([
                'id' => $gradeId,
                'student_id' => $studentId,
                'teacher_id' => $request->user()->id,
                'subject_id' => $request->subject_id,
                'school_year' => $advisory->school_year,
                'semester' => $request->semester,
                'grade' => $request->grade,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // KUNIN ANG DETAILS NG TEACHER, STUDENT AT SECTION
            $currentUser = $request->user();
            $teacherName = $currentUser->first_name . ' ' . $currentUser->last_name;
            $student = DB::table('users')->where('id', $studentId)->first();
            $studentName = $student ? ($student->first_name . ' ' . $student->last_name) : 'a student';
            $section = $advisory->section;

            // KUNIN ANG SUBJECT PARA SA LOG
            $subject = DB::table('subjects')->where('id', $request->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'a subject';

            // NOTIFY ADMINS WITH DYNAMIC DESCRIPTION
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                DB::table('notifications')->insert([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $admin->id,
                    'description' => "Teacher: {$teacherName} submitted a grade for {$studentName} ({$section}).",
                    'link' => "/admin/student-grades",
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Encoded Final Grade',
                'description' => "Submitted a final grade for {$studentName} in {$subjectName} ({$section}) for admin approval."
            ]);

            $newGrade = DB::table('final_grades')->where('id', $gradeId)->first();
            return response()->json(['message' => 'Grade encoded.', 'grade' => $newGrade], 201);
        } catch (\Exception $e) {
            Log::error('Store Student Grade Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Update Student Grade
    public function updateStudentGrade(Request $request, $classId, $studentId, $gradeId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $request->validate([
                'subject_id' => 'required',
                'semester' => 'required',
                'grade' => ['required', 'numeric', 'between:1,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            ]);

            $advisory = AdvisoryClass::where('teacher_id', $request->user()->id)->findOrFail($classId);

            DB::table('final_grades')
                ->where('id', $gradeId)
                ->where('student_id', $studentId)
                ->where('teacher_id', $request->user()->id) // Extra safety net
                ->whereNull('deleted_at')
                ->update([
                'subject_id' => $request->subject_id,
                'semester' => $request->semester,
                'grade' => $request->grade,
                'status' => 'pending',
                'updated_at' => now()
            ]);

            // KUNIN ANG DETAILS PARA SA LOG
            $advisory = DB::table('advisory_classes')->where('id', $classId)->first();
            $student = DB::table('users')->where('id', $studentId)->first();
            $studentName = $student ? ($student->first_name . ' ' . $student->last_name) : 'a student';
            $subject = DB::table('subjects')->where('id', $request->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'a subject';
            $section = $advisory ? $advisory->section : 'the class';
            
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Final Grade',
                'description' => "Updated the pending final grade of {$studentName} in {$subjectName} ({$section})."
            ]);

            return response()->json(['message' => 'Grade updated.'], 200);
        } catch (\Exception $e) {
            Log::error('Update Student Grade Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }
}