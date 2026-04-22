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
use Carbon\Carbon;

class StudentClassroomController extends Controller
{
    // View Classrooms
    public function index()
    {
        try {
            $studentId = Auth::id();

            $classrooms = Classroom::whereHas('students', function ($query) use ($studentId) {
                $query->where('classroom_student.student_id', $studentId)
                      ->where('classroom_student.status', 'approved');
            })
            ->with(['creator', 'subject', 'strand'])
            ->withCount(['students as enrolled_count' => function ($query) {
                $query->where('classroom_student.status', 'approved');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json($classrooms, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }

    // Join Classroom
    public function joinClassroom(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string'
            ]);

            $studentId = Auth::id();
            
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

            DB::table('classroom_student')->insert([
                'id' => Str::uuid(),
                'classroom_id' => $classroom->id,
                'student_id' => $studentId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // SUBJECT DESCRIPTION
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';

            // NOTIFY TEACHER (Request Join)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student " . Auth::user()->first_name . " " . Auth::user()->last_name . " requested to join {$subjectName} ({$classroom->section}).",
                'link' => "/teacher/classrooms/{$classroom->id}/people", // Direkta sa People tab
                'is_read' => false,
                'created_at' => now(), 
                'updated_at' => now(),
            ]);

            // ACTIVITY LOG 
            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Requested to Join Classroom',
                'description' => "Sent a request to join the classroom {$subjectName} ({$classroom->section})."
            ]);

            return response()->json(['message' => 'Join request sent! Please wait for your teacher to approve.'], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to join classroom: ' . $e->getMessage()], 500);
        }
    }

    // Enrolled Classrooms
    public function show($id)
    {
        try {
            $studentId = Auth::id();
            
            $classroom = Classroom::with(['creator', 'subject', 'strand'])
                ->withCount(['students as enrolled_count' => function ($query) {
                    $query->where('classroom_student.status', 'approved');
                }])
                ->whereHas('students', function ($query) use ($studentId) {
                    $query->where('classroom_student.student_id', $studentId)
                          ->where('classroom_student.status', 'approved');
                })
                ->findOrFail($id);

            return response()->json($classroom, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Classroom not found or unauthorized.'], 404);
        }
    }

    // View Classworks
    public function stream($id)
    {
        try {
            $studentId = Auth::id();

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

                    // CHECK NATIN KUNG MAY GRADE NA O KUNG RETURNED
                    if ($submission->grade !== null) {
                        $cw->student_status = 'GRADED';
                    } elseif ($submission->status === 'pending' && $submission->teacher_feedback !== null) {
                        $cw->student_status = 'RETURNED';
                    } elseif ($submission->status === 'late_submission') {
                        $cw->student_status = 'DONE LATE';
                    } else {
                        $cw->student_status = 'DONE';
                    }
                } else {
                    if ($cw->deadline) {
                        $deadline = Carbon::parse($cw->deadline);
                        $now = Carbon::now();

                        if ($now->greaterThan($deadline)) {
                            $cw->student_status = 'MISSING';
                        } elseif ($now->diffInDays($deadline, false) >= 0 && $now->diffInDays($deadline, false) <= 2) {
                            $cw->student_status = 'DUE SOON';
                        } else {
                            $cw->student_status = 'PENDING';
                        }
                    } else {
                        $cw->student_status = 'PENDING';
                    }
                }
                
                $cw->student_submission = $submission;
            }

            return response()->json($classworks, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to load stream: ' . $e->getMessage()], 500);
        }
    }

    // Submit Work
    public function submitWork(Request $request, $classworkId)
    {
        try {
            $request->validate([
                'files.*' => 'file|max:51200' 
            ]);

            $studentId = Auth::id();
            $classwork = Classwork::findOrFail($classworkId);

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

            // RESUBMISSION LOGIC (Overwrite luma kung Returned by Teacher)
            if ($existingSubmission) {
                if ($existingSubmission->status === 'pending' && $existingSubmission->teacher_feedback !== null) {
                    
                    // Delete old files
                    $files = File::where('attachable_type', 'classwork_submission')
                        ->where('attachable_id', $existingSubmission->id)
                        ->get();

                    foreach ($files as $file) {
                        $relativePath = str_replace('/storage/', '', $file->path);
                        Storage::disk('public')->delete($relativePath);
                        $file->delete();
                    }

                    // Update Submission Data
                    DB::table('classwork_submissions')->where('id', $existingSubmission->id)->update([
                        'status' => $status,
                        'teacher_feedback' => null, // Clear feedback dahil nag-resubmit na
                        'submitted_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $submissionId = $existingSubmission->id;

                } else {
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

            // Save Files
            if ($request->hasFile('files')) {
                $user = Auth::user();
                $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
                $destinationPath = "users_files/{$folderName}/submissions";

                foreach ($request->file('files') as $uploadedFile) {
                    $filename = $uploadedFile->getClientOriginalName();
                    $path = $uploadedFile->storeAs($destinationPath, time() . '_' . $filename, 'public');

                    File::create([
                        'id' => (string) Str::uuid(),
                        'owner_id' => $user->id,
                        'name' => $filename,
                        'path' => '/storage/' . $path,
                        'file_extension' => $uploadedFile->getClientOriginalExtension(),
                        'file_size' => $uploadedFile->getSize(),
                        'attachable_type' => 'classwork_submission', 
                        'attachable_id' => $submissionId
                    ]);
                }
            }

            $classroom = Classroom::find($classwork->classroom_id);

            // SUBJECT DESCRIPTION
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';
            
            // PANGALAN NG ESTUDYANTE
            $studentName = Auth::user()->first_name . ' ' . Auth::user()->last_name;
            // Notify Teacher (Submit Student Work)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student {$studentName} submitted their work for '{$classwork->title}' in {$subjectName} ({$classroom->section}).",
                'link' => "/teacher/classrooms/{$classwork->classroom_id}/stream",
                'is_read' => false,
                'created_at' => now(), 
                'updated_at' => now(),
            ]);

            // ACTIVITY LOG TRIGGER: Submit Work
            $logActionText = $isResubmission ? 'Resubmitted Classwork' : 'Submitted Classwork';
            $cwType = ucfirst($classwork->type);
            ActivityLog::create([
                'user_id' => $studentId,
                'action' => $logActionText,
                'description' => "Submitted work for the {$cwType} '{$classwork->title}' in {$subjectName} ({$classroom->section})."
            ]);

            return response()->json(['message' => 'Work turned in successfully!'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to submit work: ' . $e->getMessage()], 500);
        }
    }

    // Unsubmit Student Work
    public function unsubmitWork($classworkId)
    {
        try {
            $studentId = Auth::id();
            $classwork = Classwork::findOrFail($classworkId);

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

            // i-unsubmit
            $files = File::where('attachable_type', 'classwork_submission')
                ->where('attachable_id', $submission->id)
                ->get();

            foreach ($files as $file) {
                $relativePath = str_replace('/storage/', '', $file->path);
                Storage::disk('public')->delete($relativePath);
                $file->delete();
            }

            DB::table('classwork_submissions')->where('id', $submission->id)->delete();

            $classroom = Classroom::find($classwork->classroom_id);

            // KUNIN ANG SUBJECT
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';

            // Notify Teacher (Unsubmit ang Student work)
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => Auth::user()->first_name . " unsubmitted their work for '{$classwork->title}'.",
                'link' => "/teacher/classrooms/{$classwork->classroom_id}/stream",
                'is_read' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // ACTIVITY LOG TRIGGER: Unsubmit Work
            $cwType = ucfirst($classwork->type);
            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Unsubmitted Classwork',
                'description' => "Unsubmitted work for the {$cwType} '{$classwork->title}' in {$subjectName} ({$classroom->section})."
            ]);

            return response()->json(['message' => 'Work unsubmitted successfully!'], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to unsubmit work: ' . $e->getMessage()], 500);
        }
    }

    // View and Fetching Grades
    public function grades($id)
    {
        try {
            $studentId = Auth::id();
            $grades = DB::table('classwork_submissions')
                ->join('classworks', 'classwork_submissions.classwork_id', '=', 'classworks.id')
                ->where('classworks.classroom_id', $id)
                ->where('classwork_submissions.student_id', $studentId)
                ->where('classworks.type', '!=', 'material')
                ->whereNotNull('classwork_submissions.grade')
                ->select('classworks.title', 'classworks.type', 'classwork_submissions.grade', 'classworks.points')
                ->orderBy('classworks.created_at', 'desc')
                ->get();

            return response()->json($grades, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to load grades: ' . $e->getMessage()], 500);
        }
    }
}