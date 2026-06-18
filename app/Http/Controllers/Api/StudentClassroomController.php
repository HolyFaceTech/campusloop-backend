<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\File; 
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage; 
use Illuminate\Support\Facades\Log; 
use Carbon\Carbon;
use App\Support\PublicFileStorage;

class StudentClassroomController extends Controller
{
    // security
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // View Classrooms
    public function index(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $search = $request->input('search', '');
            $entries = (int) $request->input('entries', 12); 

            $query = Classroom::whereHas('students', function ($query) use ($studentId) {
                $query->where('classroom_student.student_id', $studentId)
                      ->where('classroom_student.status', 'approved');
            })
            ->with(['creator', 'subject', 'strand'])
            ->withCount(['students as enrolled_count' => function ($query) {
                $query->where('classroom_student.status', 'approved')
                      ->whereNull('users.deleted_at');
            }]);

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('subject', function($sub) use ($search) {
                        $sub->where('description', 'LIKE', "%{$search}%")
                            ->orWhere('code', 'LIKE', "%{$search}%");
                    })
                    ->orWhere('section', 'LIKE', "%{$search}%")
                    ->orWhereHas('creator', function($creator) use ($search) {
                        $creator->where('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%");
                    });
                });
            }

            $classrooms = $query->orderBy('created_at', 'desc')->paginate($entries);

            return response()->json($classrooms, 200);

        } catch (\Exception $e) {
            Log::error('Student Fetch Classrooms Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching classrooms.'], 500);
        }
    }

    // Join Classroom
    public function joinClassroom(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $request->validate([
                'code' => 'required|string'
            ]);

            $studentId = $request->user()->id;
            $classroom = Classroom::where('code', $request->code)->first();

            if (!$classroom) {
                return response()->json(['message' => 'Classroom not found. Please check the code.'], 404);
            }

            $existing = DB::table('classroom_student')
                ->where('classroom_id', $classroom->id)
                ->where('student_id', $studentId)
                ->first();

            if ($existing) {
                if ($existing->status === 'pending') {
                    return response()->json(['message' => 'You already have a pending request for this classroom. Please wait for the teacher to approve.'], 400);
                }
                return response()->json(['message' => 'You are already enrolled in this classroom.'], 400);
            }

            DB::beginTransaction();

            DB::table('classroom_student')->insert([
                'id' => Str::uuid(),
                'classroom_id' => $classroom->id,
                'student_id' => $studentId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';

            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student " . $request->user()->first_name . " " . $request->user()->last_name . " requested to join {$subjectName} ({$classroom->section}).",
                'link' => "/teacher/classrooms/{$classroom->id}/people", 
                'is_read' => false,
                'created_at' => now(), 
                'updated_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Requested to Join Classroom',
                'description' => "Sent a request to join the classroom {$subjectName} ({$classroom->section})."
            ]);

            DB::commit(); 
            return response()->json(['message' => 'Join request sent! Please wait for your teacher to approve.'], 200);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('Student Join Classroom Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while joining.'], 500);
        }
    }

    // Enrolled Classrooms
    public function show(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            
            $classroom = Classroom::with(['creator', 'subject', 'strand'])
                ->withCount(['students as enrolled_count' => function ($query) {
                    $query->where('classroom_student.status', 'approved')
                          ->whereNull('users.deleted_at');
                }])
                ->whereHas('students', function ($query) use ($studentId) {
                    $query->where('classroom_student.student_id', $studentId)
                          ->where('classroom_student.status', 'approved');
                })
                ->findOrFail($id);

            return response()->json($classroom, 200);

        } catch (\Exception $e) {
            Log::error('Student Show Classroom Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Classroom not found or unauthorized.'], 404);
        }
    }

    // View Classworks
    public function stream(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;

            $classworks = Classwork::with([
                'files', 
                'form',
                'comments' => function ($query) {
                    $query->whereNull('parent_id')
                          ->with(['user', 'replies.user'])
                          ->orderBy('created_at', 'asc');
                }
            ])
            ->where('classroom_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

            foreach ($classworks as $cw) {
                $submission = DB::table('classwork_submissions')
                    ->where('classwork_id', $cw->id)
                    ->where('student_id', $studentId)
                    ->first();
                
                if ($submission) {
                    $submission->files = File::where('attachable_type', 'classwork_submission')
                        ->where('attachable_id', $submission->id)
                        ->get();

                    if ($submission->status === 'graded') {
                        $cw->student_status = 'graded';
                    } elseif ($submission->status === 'late_submission') {
                        $cw->student_status = 'late_submission';
                    } elseif (!is_null($submission->teacher_feedback) && is_null($submission->grade)) {
                        $cw->student_status = 'returned';
                    } else {
                        $cw->student_status = 'turned_in';
                    }
                } else {
                    if ($cw->deadline && Carbon::now()->greaterThan(Carbon::parse($cw->deadline))) {
                        $cw->student_status = 'missing';
                    } else {
                        $cw->student_status = 'pending';
                    }
                }
                
                $cw->student_submission = $submission;
            }

            return response()->json($classworks, 200);

        } catch (\Exception $e) {
            Log::error('Student Stream Fetch Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching stream.'], 500);
        }
    }

    // Submit Work
    public function submitWork(Request $request, $classworkId)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        $request->validate([
            'files' => 'nullable|array|max:10', 
            'files.*' => 'file|max:51200|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,png,jpg,jpeg,gif,mp4,avi,mov'
        ]);

        
        $classwork = Classwork::findOrFail($classworkId);

        try {
            $studentId = $request->user()->id;

            $existingSubmission = DB::table('classwork_submissions')
                ->where('classwork_id', $classworkId)
                ->where('student_id', $studentId)
                ->first();

            $now = now();
            $status = 'pending';

            if ($classwork->deadline && $now->greaterThan(Carbon::parse($classwork->deadline))) {
                $status = 'late_submission';
            }

            $isResubmission = false;

            DB::beginTransaction();

            if ($existingSubmission) {
                if ($existingSubmission->status === 'pending' && !is_null($existingSubmission->teacher_feedback)) {
                    $isResubmission = true;
                    
                    $files = File::where('attachable_type', 'classwork_submission')
                        ->where('attachable_id', $existingSubmission->id)
                        ->get();

                    // storage path
                    foreach ($files as $file) {
                        PublicFileStorage::deleteStored($file->getRawOriginal('path'));
                        $file->delete();
                    }

                    DB::table('classwork_submissions')->where('id', $existingSubmission->id)->update([
                        'status' => $status,
                        'teacher_feedback' => null, 
                        'submitted_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $submissionId = $existingSubmission->id;

                } else {
                    DB::rollBack();
                    return response()->json(['message' => 'Work already submitted.'], 400);
                }
            } else {
                $submissionId = (string) Str::uuid();
                DB::table('classwork_submissions')->insert([
                    'id' => $submissionId,
                    'classwork_id' => $classworkId,
                    'student_id' => $studentId,
                    'status' => $status,
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($request->hasFile('files')) {
                $user = $request->user();
                $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
                $destinationPath = "users_files/{$folderName}/submissions";

                foreach ($request->file('files') as $uploadedFile) {
                    $filename = $uploadedFile->getClientOriginalName();

                    File::create([
                        'id' => (string) Str::uuid(),
                        'owner_id' => $user->id,
                        'name' => $filename,
                        'path' => PublicFileStorage::storeUploaded($uploadedFile, $destinationPath),
                        'file_extension' => $uploadedFile->getClientOriginalExtension(),
                        'file_size' => $uploadedFile->getSize(),
                        'attachable_type' => 'classwork_submission',
                        'attachable_id' => $submissionId,
                    ]);
                }
            }

            $classroom = Classroom::find($classwork->classroom_id);
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';
            $studentName = $request->user()->first_name . ' ' . $request->user()->last_name;
            
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student {$studentName} submitted their work for '{$classwork->title}' in {$subjectName} ({$classroom->section}).",
                'link' => "/teacher/classrooms/{$classwork->classroom_id}/stream",
                'is_read' => false,
                'created_at' => now(), 
                'updated_at' => now(),
            ]);

            $logActionText = $isResubmission ? 'Resubmitted Classwork' : 'Submitted Classwork';
            $cwType = ucfirst($classwork->type);

            ActivityLog::create([
                'user_id' => $studentId,
                'action' => $logActionText,
                'description' => "Submitted work for the {$cwType} '{$classwork->title}' in {$subjectName} ({$classroom->section})."
            ]);

            DB::commit(); 
            return response()->json(['message' => 'Work turned in successfully!'], 200);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('Student Submit Work Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while submitting work.'], 500);
        }
    }

    // Unsubmit Student Work
    public function unsubmitWork(Request $request, $classworkId)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        $classwork = Classwork::findOrFail($classworkId);

        try {
            $studentId = $request->user()->id;

            if ($classwork->form_id) {
                return response()->json(['message' => 'Cannot unsubmit a Quiz or Exam form.'], 400);
            }

            if ($classwork->deadline && now()->greaterThan(Carbon::parse($classwork->deadline))) {
                return response()->json(['message' => 'Cannot unsubmit because the deadline has already passed.'], 400);
            }

            $submission = DB::table('classwork_submissions')
                ->where('classwork_id', $classworkId)
                ->where('student_id', $studentId)
                ->first();

            if (!$submission) {
                return response()->json(['message' => 'No submission found.'], 404);
            }

            DB::beginTransaction();

            $files = File::where('attachable_type', 'classwork_submission')
                ->where('attachable_id', $submission->id)
                ->get();

            // storage path
            foreach ($files as $file) {
                PublicFileStorage::deleteStored($file->path);
                $file->delete();
            }

            DB::table('classwork_submissions')->where('id', $submission->id)->delete();
            $classroom = Classroom::find($classwork->classroom_id);
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';

            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => $request->user()->first_name . " unsubmitted their work for '{$classwork->title}'.",
                'link' => "/teacher/classrooms/{$classwork->classroom_id}/stream",
                'is_read' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $cwType = ucfirst($classwork->type);

            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Unsubmitted Classwork',
                'description' => "Unsubmitted work for the {$cwType} '{$classwork->title}' in {$subjectName} ({$classroom->section})."
            ]);

            DB::commit(); 
            return response()->json(['message' => 'Work unsubmitted successfully!'], 200);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('Student Unsubmit Work Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while unsubmitting work.'], 500);
        }
    }

    // View and Fetching Grades
    public function grades(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access.'], 403);
        }

        try {
            $studentId = $request->user()->id;

            $gradedWorks = DB::table('classwork_submissions')
                ->join('classworks', 'classwork_submissions.classwork_id', '=', 'classworks.id')
                ->where('classworks.classroom_id', $id)
                ->where('classwork_submissions.student_id', $studentId)
                ->where('classworks.type', '!=', 'material')
                ->whereNotNull('classwork_submissions.grade')
                ->select('classwork_submissions.grade', 'classworks.points')
                ->get();

            $totalEarned = 0;
            $totalPossible = 0;

            foreach ($gradedWorks as $work) {
                $totalEarned += (float) $work->grade;
                $totalPossible += (float) $work->points;
            }
            $percentage = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100, 1) : 0;
            $search = $request->input('search', '');
            $filter = $request->input('filter', 'all');
            $sort = $request->input('sort', 'newest');
            $entries = (int) $request->input('entries', 10);

            $query = DB::table('classworks')
                ->leftJoin('classwork_submissions', function($join) use ($studentId) {
                    $join->on('classworks.id', '=', 'classwork_submissions.classwork_id')
                         ->where('classwork_submissions.student_id', '=', $studentId);
                })
                ->where('classworks.classroom_id', $id)
                ->where('classworks.type', '!=', 'material')
                ->whereNull('classworks.deleted_at')
                ->select(
                    'classworks.id',
                    'classworks.title',
                    'classworks.type',
                    'classworks.points',
                    'classworks.deadline',
                    'classworks.created_at',
                    'classwork_submissions.grade',
                    'classwork_submissions.status as submission_status',
                    'classwork_submissions.teacher_feedback',
                    'classwork_submissions.submitted_at'
                );

            if (!empty($search)) {
                $query->where('classworks.title', 'LIKE', "%{$search}%");
            }

            if ($filter !== 'all') {
                $query->where('classworks.type', $filter);
            }

            if ($sort === 'newest') {
                $query->orderByRaw('COALESCE(classwork_submissions.submitted_at, classworks.created_at) DESC');
            } else {
                $query->orderByRaw('COALESCE(classwork_submissions.submitted_at, classworks.created_at) ASC');
            }

            $paginated = $query->paginate($entries);

            $now = Carbon::now();
            foreach ($paginated as $cw) {
                if ($cw->submission_status) {
                    if ($cw->submission_status === 'graded') {
                        $cw->student_status = 'graded';
                    } elseif ($cw->submission_status === 'late_submission') {
                        $cw->student_status = 'late_submission';
                    } elseif (!is_null($cw->teacher_feedback) && is_null($cw->grade)) {
                        $cw->student_status = 'returned';
                    } else {
                        $cw->student_status = 'turned_in';
                    }
                } else {
                    if ($cw->deadline && $now->greaterThan(Carbon::parse($cw->deadline))) {
                        $cw->student_status = 'missing';
                    } else {
                        $cw->student_status = 'pending';
                    }
                }

                $cw->student_submission = (object) [
                    'submitted_at' => $cw->submitted_at,
                    'grade' => $cw->grade
                ];
            }

            return response()->json([
                'grades' => $paginated,
                'percentage' => $percentage
            ], 200);

        } catch (\Exception $e) {
            Log::error('Student Fetch Grades Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching grades.'], 500);
        }
    }
}