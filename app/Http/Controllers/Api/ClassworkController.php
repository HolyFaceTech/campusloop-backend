<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\File;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\NewClassworkPosted;
use App\Support\PublicFileStorage;

class ClassworkController extends Controller
{
    // security
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // view classwork
    public function index(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classroom = Classroom::where('creator_id', $request->user()->id)->findOrFail($classroomId);
            $classworks = Classwork::with([
                    'files', 
                    'form',
                    'comments' => function ($query) {
                        $query->whereNull('parent_id')
                            ->with(['user', 'replies.user'])
                            ->orderBy('created_at', 'asc');
                    }
                ])
                ->where('classroom_id', $classroomId)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->get();
                
            return response()->json($classworks, 200);

        } catch (\Exception $e) {
            Log::error('Fetch Classworks Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching classworks.'], 500);
        }
    }

    // create classwork
    public function store(Request $request, $classroomId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classroom = Classroom::with('subject')->where('creator_id', $request->user()->id)->findOrFail($classroomId);
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'type' => 'required|in:assignment,activity,quiz,exam,material',
                'instruction' => 'required|string',
                'points' => 'nullable|integer',
                'deadline' => 'nullable|date',
                'link' => 'nullable|url|starts_with:http://,https://',
                'form_id' => 'nullable|uuid|exists:forms,id',
                'files' => 'nullable|array|max:10',
                'files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,png,jpg,jpeg,gif,mp4,avi,mov|max:51200'
            ]);

            $classwork = Classwork::create(array_merge($validated, ['classroom_id' => $classroomId]));

            if ($request->hasFile('files')) {
                $user = Auth::user();
                $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
                $destinationPath = "users_files/{$folderName}/classworks";

                foreach ($request->file('files') as $uploadedFile) {
                    $originalName = $uploadedFile->getClientOriginalName();
                    // System-generated hash para iwas File Name Exploits (Directory Traversal)
                    $path = $uploadedFile->store($destinationPath, 'public');
                    // storage path
                    File::create([
                        'id' => (string) Str::uuid(),
                        'owner_id' => $user->id,
                        'name' => $originalName,
                        'path' => PublicFileStorage::publicPath($path),
                        'file_extension' => $uploadedFile->getClientOriginalExtension(),
                        'file_size' => $uploadedFile->getSize(),
                        'attachable_type' => Classwork::class,
                        'attachable_id' => $classwork->id
                    ]);
                }
            }

            $teacher = $request->user();
            $teacherName = $teacher->first_name . ' ' . $teacher->last_name;
            $classworkType = ucfirst($classwork->type);
            $subjectName = $classroom->subject ? $classroom->subject->description : 'the class';
            $approvedStudents = $classroom->students()->wherePivot('status', 'approved')->get();
            $notifications = [];
            $currentTime = now()->toDateTimeString();
            $frontendBaseUrl = env('FRONTEND_URL', 'http://localhost:5173'); 
            $linkPath = "/student/classrooms/{$classroomId}/stream";
            $fullLink = $frontendBaseUrl . $linkPath;

            foreach ($approvedStudents as $student) {
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $student->id,
                    'description' => "Teacher {$teacherName} posted a new {$classworkType}: '{$classwork->title}' in {$subjectName}.",
                    'link' => $linkPath,
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];

                // Asynchronous (Background Task) Queue Email
                if (!empty($student->email)) {
                    Mail::to($student->email)->queue(new NewClassworkPosted(
                        $student->first_name,
                        $teacherName,
                        $subjectName,
                        $classwork->type,
                        $classwork->title,
                        $classwork->deadline,
                        $fullLink
                    ));
                }
            }

            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            ActivityLog::create([
                'user_id' => $teacher->id,
                'action' => 'Created Classwork',
                'description' => "Posted a new {$classworkType}: '{$classwork->title}' in {$subjectName}."
            ]);

            return response()->json(['message' => 'Classwork posted successfully!', 'classwork' => $classwork->load(['files', 'form'])], 201);

        } catch (\Exception $e) {
            Log::error('Create Classwork Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to create classwork.'], 500);
        }
    }

    // update classwork
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classwork = Classwork::whereHas('classroom', function($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'type' => 'required|in:assignment,activity,quiz,exam,material',
                'instruction' => 'required|string',
                'points' => 'nullable|integer',
                'deadline' => 'nullable|date',
                'link' => 'nullable|url|starts_with:http://,https://',
                'form_id' => 'nullable|uuid|exists:forms,id',
                'files' => 'nullable|array|max:10',
                'files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,png,jpg,jpeg,gif,mp4,avi,mov|max:51200'
            ]);

            $dataToUpdate = $validated;
            $dataToUpdate['link'] = $request->input('link', null);
            $dataToUpdate['form_id'] = $request->input('form_id', null);
            $classwork->update($dataToUpdate);

            if ($request->has('deleted_file_ids')) {
                $filesToDelete = File::whereIn('id', $request->deleted_file_ids)->get();
                foreach ($filesToDelete as $f) {
                    PublicFileStorage::deleteStored($f->path);
                    $f->delete();
                }
            }

            if ($request->hasFile('files')) {
                $user = Auth::user();
                $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
                $destinationPath = "users_files/{$folderName}/classworks";

                foreach ($request->file('files') as $uploadedFile) {
                    $originalName = $uploadedFile->getClientOriginalName();
                    // System-generated hash para safe filename
                    $path = $uploadedFile->store($destinationPath, 'public');
                    // storage path
                    File::create([
                        'id' => (string) Str::uuid(),
                        'owner_id' => $user->id,
                        'name' => $originalName,
                        'path' => PublicFileStorage::publicPath($path),
                        'file_extension' => $uploadedFile->getClientOriginalExtension(),
                        'file_size' => $uploadedFile->getSize(),
                        'attachable_type' => Classwork::class,
                        'attachable_id' => $classwork->id
                    ]);
                }
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Classwork',
                'description' => "Updated the details of " . ucfirst($classwork->type) . ": '{$classwork->title}'."
            ]);

            return response()->json(['message' => 'Classwork updated successfully!'], 200);

        } catch (\Exception $e) {
            Log::error('Update Classwork Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to update classwork.'], 500);
        }
    }

    // delete classwork
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classwork = Classwork::with('classroom.subject')->whereHas('classroom', function($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->findOrFail($id);

            $title = $classwork->title ?? 'Activity';
            $type = ucfirst($classwork->type);
            $subjectName = $classwork->classroom && $classwork->classroom->subject ? $classwork->classroom->subject->description : 'the class';
            $classwork->delete(); 

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Classwork',
                'description' => "Moved the {$type} '{$title}' from {$subjectName} to the recycle bin."
            ]);

            return response()->json(['message' => 'Classwork moved to recycle bin.'], 200);
            
        } catch (\Exception $e) {
            Log::error('Delete Classwork Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to delete classwork.'], 500);
        }
    }

    // view respondents
    public function getSubmissions(Request $request, $classworkId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classwork = Classwork::whereHas('classroom', function($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->findOrFail($classworkId);

            $search = $request->input('search');
            $entries = $request->input('entries', 10);

            $query = DB::table('classroom_student')
                ->join('users', 'classroom_student.student_id', '=', 'users.id')
                ->where('classroom_student.classroom_id', $classwork->classroom_id)
                ->where('classroom_student.status', 'approved')
                ->select('users.id', 'users.first_name', 'users.last_name', 'users.lrn', 'users.avatar');

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('users.first_name', 'like', "%{$search}%")
                      ->orWhere('users.last_name', 'like', "%{$search}%")
                      ->orWhere('users.lrn', 'like', "%{$search}%");
                });
            }

            $students = $query->orderBy('users.last_name', 'asc')->paginate($entries);

            // Fetch submissions per student sa current page
            foreach ($students->items() as $student) {
                $submission = DB::table('classwork_submissions')
                    ->where('classwork_id', $classworkId)
                    ->where('student_id', $student->id)
                    ->first();
                
                if ($submission) {
                    $submission->files = File::where('attachable_type', 'classwork_submission')
                        ->where('attachable_id', $submission->id)
                        ->get();
                }

                $student->submission = $submission;
            }

            return response()->json($students, 200);

        } catch (\Exception $e) {
            Log::error('Get Submissions Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to fetch submissions.'], 500);
        }
    }

    // grade submission
    public function gradeSubmission(Request $request, $classworkId, $studentId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $classwork = Classwork::whereHas('classroom', function($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->findOrFail($classworkId);

            $maxPoints = $classwork->points ? $classwork->points : 100;

            // Strict checking sa backend kung ilan talaga max points
            $request->validate([
                'grade' => 'required|numeric|min:0|max:' . $maxPoints
            ]);

            $submission = DB::table('classwork_submissions')
                ->where('classwork_id', $classworkId)
                ->where('student_id', $studentId)
                ->first();

            if (!$submission) {
                return response()->json(['message' => 'No submission found to grade.'], 404);
            }

            DB::table('classwork_submissions')
                ->where('id', $submission->id)
                ->update([
                    'grade' => $request->grade,
                    'status' => 'graded', 
                    'updated_at' => now()
                ]);

            $student = DB::table('users')->where('id', $studentId)->first();
            $studentName = $student ? $student->first_name . ' ' . $student->last_name : 'a student';
            $classroom = DB::table('classrooms')->where('id', $classwork->classroom_id)->first();
            $subject = $classroom ? DB::table('subjects')->where('id', $classroom->subject_id)->first() : null;
            $teacherName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $subjectName = $subject ? $subject->description : 'the class';
            $classworkType = ucfirst($classwork->type); 
            $classworkTitle = $classwork->title ?? $classwork->name ?? 'Activity';
            $sectionName = $classroom ? "({$classroom->section})" : "";
            $scoreString = $classwork->points ? "{$request->grade}/{$classwork->points}" : "{$request->grade}";

            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $studentId,
                'description' => "Teacher {$teacherName} graded your {$classworkType}: '{$classworkTitle}' in {$subjectName} {$sectionName}. Score: {$scoreString}",
                'link' => "/student/classrooms/" . ($classroom ? $classroom->id : ''),
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Graded Submission',
                'description' => "Graded {$studentName}'s submission for the {$classworkType}: '{$classworkTitle}'."
            ]);

            return response()->json(['message' => 'Grade saved successfully!'], 200);

        } catch (\Exception $e) {
            Log::error('Grade Submission Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to save grade. Exceeds max points limit.'], 500);
        }
    }

    // Unsubmit Student Submission
    public function returnSubmission(Request $request, $classworkId, $studentId)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access.'], 403);

        try {
            $request->validate([
                'feedback' => 'required|string'
            ]);

            $classwork = Classwork::whereHas('classroom', function($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->findOrFail($classworkId);

            $submission = DB::table('classwork_submissions')
                ->where('classwork_id', $classworkId)
                ->where('student_id', $studentId)
                ->first();

            if (!$submission) {
                return response()->json(['message' => 'No submission found.'], 404);
            }

            DB::table('classwork_submissions')
                ->where('id', $submission->id)
                ->update([
                    'status' => 'pending', 
                    'teacher_feedback' => $request->feedback,
                    'grade' => null, 
                    'updated_at' => now()
                ]);

            $student = DB::table('users')->where('id', $studentId)->first();
            $studentName = $student ? $student->first_name . ' ' . $student->last_name : 'a student';
            $classroom = DB::table('classrooms')->where('id', $classwork->classroom_id)->first();
            $subject = $classroom ? DB::table('subjects')->where('id', $classroom->subject_id)->first() : null;
            $teacherName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $subjectName = $subject ? $subject->description : 'the class';
            $classworkType = ucfirst($classwork->type); 
            $classworkTitle = $classwork->title ?? $classwork->name ?? 'Activity';
            $sectionName = $classroom ? "({$classroom->section})" : "";  
            $feedbackSnippet = Str::limit($request->feedback, 30);

            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $studentId,
                'description' => "Teacher {$teacherName} returned your {$classworkType}: '{$classworkTitle}' in {$subjectName} {$sectionName}. Feedback: \"{$feedbackSnippet}\"",
                'link' => "/student/classrooms/" . ($classroom ? $classroom->id : ''),
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Returned Submission',
                'description' => "Returned {$studentName}'s submission for the {$classworkType}: '{$classworkTitle}' with feedback."
            ]);

            return response()->json(['message' => 'Submission returned to student with feedback.'], 200);

        } catch (\Exception $e) {
            Log::error('Return Submission Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to return submission.'], 500);
        }
    }
}