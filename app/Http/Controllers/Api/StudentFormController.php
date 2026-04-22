<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Form;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentFormController extends Controller
{
    // View Total Score/Points
    public function show($id)
    {
        try {
            $studentId = Auth::id();
            $form = Form::with('questions')->findOrFail($id);
            
            // SECURITY: Itago ang 'correct_answer'
            $form->questions->makeHidden(['correct_answer']);

            // CHECK KUNG TAPOS NA ANG STUDENT
            $existingSubmission = DB::table('form_submissions')
                ->where('form_id', $form->id)
                ->where('student_id', $studentId)
                ->first();

            // Total points calculation para maipakita sa result
            $totalPoints = $form->questions->sum('points');

            if ($existingSubmission) {
                return response()->json([
                    'already_submitted' => true,
                    'score' => $existingSubmission->score,
                    'total_points' => $totalPoints,
                    'form_name' => $form->name,
                    'student_name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                ], 200);
            }

            return response()->json([
                'already_submitted' => false,
                'form' => $form
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Form not found.'], 404);
        }
    }

    // Submit Form with Auto Checker
    public function submit(Request $request, $id)
    {
        try {
            $studentId = Auth::id();
            $form = Form::with('questions')->findOrFail($id);
            
            $classwork = Classwork::where('form_id', $form->id)->first();
            
            if (!$classwork) {
                return response()->json(['message' => 'This form is not linked to any classwork.'], 400);
            }

            $existingSubmission = DB::table('form_submissions')
                ->where('form_id', $form->id)
                ->where('student_id', $studentId)
                ->first();

            if ($existingSubmission) {
                return response()->json(['message' => 'You have already submitted this form.'], 400);
            }

            $answers = $request->input('answers', []); 
            $totalScore = 0;
            $formSubmissionId = (string) Str::uuid();

            $submissionAnswersData = [];

            foreach ($form->questions as $question) {
                $studentAnswer = isset($answers[$question->id]) ? $answers[$question->id] : '';
                $isCorrect = false;
                $pointsEarned = 0;

                if ($question->type === 'short_answer') {
                    if (strtolower(trim($studentAnswer)) === strtolower(trim($question->correct_answer))) {
                        $isCorrect = true;
                        $pointsEarned = $question->points;
                    }
                } else {
                    if ($studentAnswer === $question->correct_answer) {
                        $isCorrect = true;
                        $pointsEarned = $question->points;
                    }
                }

                $totalScore += $pointsEarned;

                $submissionAnswersData[] = [
                    'id' => (string) Str::uuid(),
                    'submission_id' => $formSubmissionId,
                    'question_id' => $question->id,
                    'student_answer' => $studentAnswer,
                    'is_correct' => $isCorrect,
                    'points_earned' => $pointsEarned,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('form_submissions')->insert([
                'id' => $formSubmissionId,
                'form_id' => $form->id,
                'student_id' => $studentId,
                'classwork_id' => $classwork->id,
                'score' => $totalScore,
                'started_at' => now(), 
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('form_submission_answers')->insert($submissionAnswersData);

            $now = now();
            $status = 'graded';
            
            if ($classwork->deadline && $now->greaterThan(\Carbon\Carbon::parse($classwork->deadline))) {
                $status = 'late_submission'; 
            }

            DB::table('classwork_submissions')->updateOrInsert(
                ['classwork_id' => $classwork->id, 'student_id' => $studentId],
                [
                    'id' => (string) Str::uuid(),
                    'status' => 'graded', 
                    'grade' => $totalScore, 
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            
            // Kunin ang Classroom at Subject
            $classroom = Classroom::findOrFail($classwork->classroom_id);
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';
            
            // I-format ang Student Name at Total Points
            $studentName = Auth::user()->first_name . ' ' . Auth::user()->last_name;
            $totalPoints = $form->questions->sum('points');
            
            // Ipadala kay Teacher ang Notification
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student {$studentName} submitted '{$form->name}' in {$subjectName} ({$classroom->section}). Score: {$totalScore}/{$totalPoints}",
                'link' => "/teacher/classrooms/{$classroom->id}/grades", // Mapupunta sa grades list ng klase
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ACTIVITY LOG
            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Submitted Quiz/Exam',
                'description' => "Completed and submitted the form '{$form->name}' in {$subjectName} ({$classroom->section})."
            ]);

            return response()->json([
                'message' => 'Form submitted successfully!',
                'score' => $totalScore,
                'student_name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                'form_name' => $form->name,
                'total_points' => $form->questions->sum('points')
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to submit form: ' . $e->getMessage()], 500);
        }
    }
}