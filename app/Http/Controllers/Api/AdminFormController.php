<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminFormController extends Controller
{
    // SECURITY FEATURE
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // View All Forms
    public function index(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $query = Form::with('creator');

        // SERVER-SIDE SEARCH
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                // Hanapin sa Form Name
                $q->where('name', 'LIKE', "%{$search}%")
                  // hanapin sa pangalan ng Teacher
                  ->orWhereHas('creator', function($q2) use ($search) {
                      $q2->where('first_name', 'LIKE', "%{$search}%")
                         ->orWhere('last_name', 'LIKE', "%{$search}%")
                         ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$search}%");
                  });
            });
        }

        // SERVER-SIDE TEACHER FILTER
        if ($request->has('teacher') && $request->teacher !== 'all') {
            $query->where('creator_id', $request->teacher);
        }

        // SERVER-SIDE SORTING
        $sortOrder = $request->has('sort') && $request->sort === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('created_at', $sortOrder);

        // PAGINATION (Default to 12 since Grid ito)
        $entries = $request->has('entries') ? (int) $request->entries : 12;
        $paginatedForms = $query->paginate($entries);

        // KUNIN ANG MGA UNIQUE TEACHERS PARA SA DROPDOWN SA FRONTEND
        $teacherIds = Form::select('creator_id')->distinct()->pluck('creator_id')->filter();
        $teachers = User::whereIn('id', $teacherIds)
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name', 'asc')
            ->get();

        return response()->json([
            'data' => $paginatedForms->items(),
            'total' => $paginatedForms->total(),
            'last_page' => $paginatedForms->lastPage(),
            'teachers' => $teachers
        ], 200);
    }

    // Bulk Delete (Soft Delete) para sa Forms
    public function destroyBulk(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate([
                'ids' => 'required|array|max:100',
                'ids.*' => 'exists:forms,id'
            ]);

            // KUNIN ANG FORMS BAGO BURAHIN PARA SA NOTIF
            $forms = Form::whereIn('id', $request->ids)->get();

            // Soft Delete
            Form::whereIn('id', $request->ids)->delete();

            // NOTIFICATION LOGIC 
            $admin = $request->user();
            $adminName = $admin ? $admin->first_name . ' ' . $admin->last_name : 'Admin';

            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach ($forms as $form) {
                // I-check kung may creator_id bago gumawa ng notification
                if ($form->creator_id) {
                    $formName = $form->name ?? 'Quiz/Exam';
                    
                    $notifications[] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $form->creator_id, // Ise-send sa mismong Teacher na gumawa
                        'description' => "Admin {$adminName} deleted your form '{$formName}'. It was moved to the Recycle Bin.",
                        'link' => "/teacher/recycle-bin", // Direkta sa recycle bin ni Teacher
                        'is_read' => false,
                        'created_at' => $currentTime,
                        'updated_at' => $currentTime,
                    ];
                }
            }

            // ISAHANG BULK INSERT
            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $count = count($request->ids);

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Deleted Forms',
                    'description' => "Moved {$count} selected form(s) to the recycle bin."
                ]);
            }

            return response()->json(['message' => 'Forms moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            Log::error('AdminFormController destroyBulk Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while deleting forms.'], 500);
        }
    }

    // Inside of Form
    public function show(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $form = Form::with(['questions' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }])->findOrFail($id);

        return response()->json($form, 200);
    }

    // Student Answers 
    public function respondents(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }
        
        $query = FormSubmission::with(['student.strand'])
            ->where('form_id', $id);

       // SERVER-SIDE SEARCH (Pangalan, Email, LRN, o Strand)
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->whereHas('student', function($q) use ($search) {
                $q->where('users.first_name', 'LIKE', "%{$search}%")
                  ->orWhere('users.last_name', 'LIKE', "%{$search}%")
                  ->orWhere(DB::raw("CONCAT(users.first_name, ' ', users.last_name)"), 'LIKE', "%{$search}%")
                  ->orWhere('users.email', 'LIKE', "%{$search}%")
                  ->orWhere('users.lrn', 'LIKE', "%{$search}%")
                  ->orWhereHas('strand', function($qStrand) use ($search) {
                      $qStrand->where('strands.name', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Pinakabagong submission sa unahan
        $query->orderBy('submitted_at', 'desc');

        // PAGINATION
        $entries = $request->has('entries') ? (int) $request->entries : 10;
        $paginatedSubmissions = $query->paginate($entries);

        // KUNIN LANG ANG MGA ANSWERS NG MGA NASA CURRENT PAGE (Para bumilis ang query)
        $submissionIds = collect($paginatedSubmissions->items())->pluck('id');
        $answers = DB::table('form_submission_answers')
            ->whereIn('submission_id', $submissionIds)
            ->get()
            ->groupBy('submission_id');

        foreach ($paginatedSubmissions->items() as $submission) {
            $submission->answers = isset($answers[$submission->id]) ? $answers[$submission->id] : [];
        }

        return response()->json([
            'data' => $paginatedSubmissions->items(),
            'total' => $paginatedSubmissions->total(),
            'last_page' => $paginatedSubmissions->lastPage()
        ], 200);
    }

    // UNSUBMIT (ADMIN CONTROL)
    public function unsubmit(Request $request, $formId, $submissionId) 
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $submission = FormSubmission::where('id', $submissionId)
                                        ->where('form_id', $formId)
                                        ->firstOrFail();

            $studentId = $submission->student_id;
            
            // Kunin ang student user para makuha ang pangalan para sa notification ni teacher
            $student = User::find($studentId);

            // KUNIN ANG DETAILS BAGO BURAHIN PARA SA NOTIF
            $form = DB::table('forms')->where('id', $formId)->first();
            $classwork = DB::table('classworks')->where('form_id', $formId)->first();

            DB::beginTransaction();

            // Alisin ang sagot sa form_submission_answers (Hard delete via DB query)
            DB::table('form_submission_answers')->where('submission_id', $submissionId)->delete();

            // Alisin ang mismong submission record NANG PERMANENTE (Hard Delete)
            $submission->forceDelete();

            // Hanapin ang classwork na nakakabit sa form na ito at alisin din ang classwork_submission record
            if ($classwork) {
                DB::table('classwork_submissions')
                    ->where('classwork_id', $classwork->id)
                    ->where('student_id', $studentId)
                    ->delete(); // Hard delete via DB query
            }

            // NOTIFICATION LOGIC FOR TEACHER & STUDENT
            if ($form && $classwork) {
                $admin = $request->user();
                $adminName = $admin->first_name . ' ' . $admin->last_name;
                $studentName = $student ? $student->first_name . ' ' . $student->last_name : 'a student';

                // Kunin ang details ng classroom para makuha ang Teacher ID, Subject, at Section
                $classroom = DB::table('classrooms')->where('id', $classwork->classroom_id)->first();
                
                $teacherId = $classroom ? $classroom->creator_id : null;
                $sectionName = $classroom ? $classroom->section : 'Unknown Section';
                
                $subject = $classroom ? DB::table('subjects')->where('id', $classroom->subject_id)->first() : null;
                $subjectName = $subject ? $subject->description : 'the class';
                
                $formName = $form->name ?? 'Quiz/Exam';

                // I-setup ang magkaibang link para sa magkaibang dashboard
                $studentLink = $classroom ? "/student/classrooms/{$classroom->id}/stream" : "/student/classrooms";
                $teacherLink = $classroom ? "/teacher/classrooms/{$classroom->id}/stream" : "/teacher/classrooms";

                $currentTime = now()->toDateTimeString();
                $notifications = [];

                // NOTIFICATION PARA KAY STUDENT
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $studentId,
                    'description' => "Admin {$adminName} has reset your submission for '{$formName}' in {$subjectName} ({$sectionName}). You can now retake it.",
                    'link' => $studentLink,
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];

                // NOTIFICATION PARA KAY TEACHER (Creator ng class)
                if ($teacherId) {
                    $notifications[] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $teacherId,
                        'description' => "Admin {$adminName} has reset the submission of {$studentName} for '{$formName}' in {$subjectName} ({$sectionName}).",
                        'link' => $teacherLink,
                        'is_read' => false,
                        'created_at' => $currentTime,
                        'updated_at' => $currentTime,
                    ];
                }

                // Isahang Insert
                if (!empty($notifications)) {
                    DB::table('notifications')->insert($notifications);
                }
            }

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Reset Student Submission',
                'description' => "Reset the submission of {$studentName} for the form '{$formName}'."
            ]);

            DB::commit();
            return response()->json(['message' => 'Student submission removed permanently and notified both teacher and student.'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Submission not found or does not belong to this form.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminFormController unsubmit Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while resetting the submission.'], 500);
        }
    }

    // Print Teacher Form
    public function printTeacherForm(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            abort(403, 'Unauthorized access. Admin privileges required.');
        }

        $form = Form::with(['creator', 'questions'])->findOrFail($id);
        $admin = $request->user(); 

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'Printed Blank Form',
            'description' => "Generated a print view for the form '{$form->name}'."
        ]);
        
        $html = view('print.teacherform', compact('form', 'admin'))->render();
        return response($html)->header('Content-Type', 'text/html');
    }

    // Print Student Answer
    public function printStudentForm(Request $request, $formId, $submissionId)
    {
        if (!$this->checkAdmin($request)) {
            abort(403, 'Unauthorized access. Admin privileges required.');
        }

        $form = Form::with(['questions'])->findOrFail($formId);

        $submission = FormSubmission::with(['student', 'answers'])
                                    ->where('id', $submissionId)
                                    ->where('form_id', $formId)
                                    ->firstOrFail();

        $admin = $request->user(); 

        $studentName = $submission->student ? $submission->student->first_name . ' ' . $submission->student->last_name : 'a student';

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'Printed Student Submission',
            'description' => "Generated a print view for {$studentName}'s submission in '{$form->name}'."
        ]);

        $html = view('print.studentform', compact('form', 'submission', 'admin'))->render();
        return response($html)->header('Content-Type', 'text/html');
    }
}