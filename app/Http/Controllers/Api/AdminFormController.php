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

class AdminFormController extends Controller
{
    // View All Forms
    public function index()
    {
        $forms = Form::with('creator')->orderBy('created_at', 'desc')->get();
        return response()->json($forms, 200);
    }

    // Bulk Delete (Soft Delete) para sa Forms
    public function destroyBulk(Request $request)
    {
        try {
            $request->validate(['ids' => 'required|array']);

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
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // Inside of Form
    public function show($id)
    {
        $form = Form::with(['questions' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }])->findOrFail($id);

        return response()->json($form, 200);
    }

    // Student Answers
    public function respondents($id)
    {
        $submissions = FormSubmission::with(['student.strand'])
            ->where('form_id', $id)
            ->orderBy('submitted_at', 'desc')
            ->get();

        $submissionIds = $submissions->pluck('id');
        $answers = DB::table('form_submission_answers')
            ->whereIn('submission_id', $submissionIds)
            ->get()
            ->groupBy('submission_id');

        foreach ($submissions as $submission) {
            $submission->answers = isset($answers[$submission->id]) ? $answers[$submission->id] : [];
        }

        return response()->json($submissions, 200);
    }

    // UNSUBMIT (ADMIN CONTROL)
    public function unsubmit(Request $request, $formId, $submissionId) 
    {
        try {
            $submission = FormSubmission::findOrFail($submissionId);
            $studentId = $submission->student_id;
            
            // Kunin ang student user para makuha ang pangalan para sa notification ni teacher
            $student = User::find($studentId);

            // KUNIN ANG DETAILS BAGO BURAHIN PARA SA NOTIF
            $form = DB::table('forms')->where('id', $formId)->first();
            $classwork = DB::table('classworks')->where('form_id', $formId)->first();

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

            return response()->json(['message' => 'Student submission removed permanently and notified both teacher and student.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to unsubmit: ' . $e->getMessage()], 500);
        }
    }

    // Print Teacher Form
    public function printTeacherForm(Request $request, $id)
    {
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
        $form = Form::with(['questions'])->findOrFail($formId);
        $submission = FormSubmission::with(['student', 'answers'])->findOrFail($submissionId);
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