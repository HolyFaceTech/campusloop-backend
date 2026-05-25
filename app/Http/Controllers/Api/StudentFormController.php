<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Form;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log; 
use Carbon\Carbon; 

class StudentFormController extends Controller
{
    // RBAC
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // view of questionare
    public function show(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $form = Form::with('questions')->findOrFail($id);
            $form->questions->makeHidden(['correct_answer']);

            $classwork = Classwork::where('form_id', $form->id)->first();
            if (!$classwork) {
                return response()->json(['message' => 'This form is not linked to any classwork.'], 400);
            }

            $isEnrolled = DB::table('classroom_student')
                ->where('classroom_id', $classwork->classroom_id)
                ->where('student_id', $studentId)
                ->where('status', 'approved')
                ->exists();

            if (!$isEnrolled) {
                return response()->json(['message' => 'Unauthorized. You are not enrolled in this classroom.'], 403);
            }

            $existingSubmission = DB::table('form_submissions')
                ->where('form_id', $form->id)
                ->where('student_id', $studentId)
                ->first();

            $totalPoints = $form->questions->sum('points');

            if ($existingSubmission) {
                if ($existingSubmission->submitted_at !== null) {
                    return response()->json([
                        'already_submitted' => true,
                        'score' => $existingSubmission->score,
                        'total_points' => $totalPoints,
                        'form_name' => $form->name,
                        'student_name' => $request->user()->first_name . ' ' . $request->user()->last_name,
                    ], 200);
                } else {
                    $savedAnswers = DB::table('form_submission_answers')
                        ->where('submission_id', $existingSubmission->id)
                        ->pluck('student_answer', 'question_id')
                        ->toArray();

                    $timeLeftSeconds = null;
                    if ($form->timer && $form->timer > 0) {
                        $startedAtStr = $existingSubmission->started_at ?? clone $existingSubmission->created_at;
                        $startedTimestamp = strtotime($startedAtStr);
                        $currentTimestamp = time();
                        
                        $elapsed = max(0, $currentTimestamp - $startedTimestamp);
                        $timeLeftSeconds = max(0, ($form->timer * 60) - $elapsed);
                    }

                    return response()->json([
                        'already_submitted' => false,
                        'form' => $form,
                        'time_left_seconds' => $timeLeftSeconds,
                        'saved_answers' => $savedAnswers
                    ], 200);
                }
            }

            DB::table('form_submissions')->insert([
                'id' => (string) Str::uuid(),
                'form_id' => $form->id,
                'student_id' => $studentId,
                'classwork_id' => $classwork->id,
                'score' => 0,
                'started_at' => now(),
                'submitted_at' => null, 
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'already_submitted' => false,
                'form' => $form,
                'time_left_seconds' => $form->timer ? ($form->timer * 60) : null,
                'saved_answers' => []
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Student Form Show Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while loading the form.'], 500);
        }
    }

    // auto save answer
    public function saveProgress(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $form = Form::findOrFail($id);

            $existingSubmission = DB::table('form_submissions')
                ->where('form_id', $form->id)
                ->where('student_id', $studentId)
                ->first();

            if (!$existingSubmission || $existingSubmission->submitted_at !== null) {
                return response()->json(['message' => 'Cannot save progress.'], 400);
            }

            $answers = $request->input('answers', []);

            foreach ($answers as $questionId => $studentAnswer) {
                if ($studentAnswer === null) continue;

                $existingAnswer = DB::table('form_submission_answers')
                    ->where('submission_id', $existingSubmission->id)
                    ->where('question_id', $questionId)
                    ->first();
                    
                if ($existingAnswer) {
                    DB::table('form_submission_answers')
                        ->where('id', $existingAnswer->id)
                        ->update([
                            'student_answer' => (string) $studentAnswer,
                            'updated_at' => now()
                        ]);
                } else {
                    DB::table('form_submission_answers')->insert([
                        'id' => (string) Str::uuid(),
                        'submission_id' => $existingSubmission->id,
                        'question_id' => $questionId,
                        'student_answer' => (string) $studentAnswer,
                        'is_correct' => false,
                        'points_earned' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['message' => 'Progress saved.'], 200);
        } catch (\Exception $e) {
            Log::error('Save Progress Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to save progress.'], 500);
        }
    }

    // submit form answer
    public function submit(Request $request, $id)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $form = Form::with('questions')->findOrFail($id);
            $classwork = Classwork::where('form_id', $form->id)->first();
            
            if (!$classwork) {
                return response()->json(['message' => 'This form is not linked to any classwork.'], 400);
            }

            $isEnrolled = DB::table('classroom_student')
                ->where('classroom_id', $classwork->classroom_id)
                ->where('student_id', $studentId)
                ->where('status', 'approved')
                ->exists();

            if (!$isEnrolled) {
                return response()->json(['message' => 'Unauthorized submission.'], 403);
            }

            $existingSubmission = DB::table('form_submissions')
                ->where('form_id', $form->id)
                ->where('student_id', $studentId)
                ->first();

            if (!$existingSubmission || $existingSubmission->submitted_at !== null) {
                return response()->json(['message' => 'Submission invalid or already submitted.'], 400);
            }

            $isLate = false;
            $now = now();

            if ($form->timer && $form->timer > 0) {
                $startedAtStr = $existingSubmission->started_at ?? $existingSubmission->created_at;
                $startedTimestamp = strtotime($startedAtStr);
                $currentTimestamp = time();
                
                $elapsedSeconds = max(0, $currentTimestamp - $startedTimestamp);
                $allowedSeconds = ($form->timer * 60) + 10; 
                
                if ($elapsedSeconds > $allowedSeconds) {
                    $isLate = true; 
                }
            }

            $answers = $request->input('answers', []); 
            $totalScore = 0;
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
                    'submission_id' => $existingSubmission->id, 
                    'question_id' => $question->id,
                    'student_answer' => $studentAnswer,
                    'is_correct' => $isCorrect,
                    'points_earned' => $pointsEarned,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::beginTransaction(); 

            DB::table('form_submissions')->where('id', $existingSubmission->id)->update([
                'score' => $totalScore,
                'submitted_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('form_submission_answers')->where('submission_id', $existingSubmission->id)->delete();
            DB::table('form_submission_answers')->insert($submissionAnswersData);

            $status = 'graded';
            if ($isLate || ($classwork->deadline && $now->greaterThan(Carbon::parse($classwork->deadline)))) {
                $status = 'late_submission';
            }

            $cwSubmission = DB::table('classwork_submissions')
                ->where('classwork_id', $classwork->id)
                ->where('student_id', $studentId)
                ->first();

            if ($cwSubmission) {
                DB::table('classwork_submissions')
                    ->where('id', $cwSubmission->id)
                    ->update([
                        'status' => $status,
                        'grade' => $totalScore,
                        'submitted_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('classwork_submissions')->insert([
                    'id' => (string) Str::uuid(),
                    'classwork_id' => $classwork->id,
                    'student_id' => $studentId,
                    'status' => $status,
                    'grade' => $totalScore,
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            
            $classroom = Classroom::findOrFail($classwork->classroom_id);
            $subject = DB::table('subjects')->where('id', $classroom->subject_id)->first();
            $subjectName = $subject ? $subject->description : 'Class';
            $studentName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $totalPoints = $form->questions->sum('points');
            
            DB::table('notifications')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $classroom->creator_id,
                'description' => "Student {$studentName} submitted '{$form->name}' in {$subjectName} ({$classroom->section}). Score: {$totalScore}/{$totalPoints}",
                'link' => "/teacher/classrooms/{$classroom->id}/grades", 
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            ActivityLog::create([
                'user_id' => $studentId,
                'action' => 'Submitted Quiz/Exam',
                'description' => "Completed and submitted the form '{$form->name}' in {$subjectName} ({$classroom->section})."
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Form submitted successfully!',
                'score' => $totalScore,
                'student_name' => $studentName,
                'form_name' => $form->name,
                'total_points' => $totalPoints
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Student Form Submit Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while submitting your form.'], 500);
        }
    }
}