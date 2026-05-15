<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormQuestion;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class FormQuestionController extends Controller
{
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // CREATE
    public function store(Request $request, $formId)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $form = Form::where('creator_id', $request->user()->id)->findOrFail($formId);

            $validated = $request->validate([
                'section' => 'nullable|string|max:255',
                'instruction' => 'nullable|string',
                'text' => 'required|string',
                'type' => 'required|in:multiple_choice,short_answer',
                'choices' => 'nullable|array|max:10',
                'choices.*' => 'required|max:255', 
                'correct_answer' => 'required|max:255', 
                'points' => 'required|integer|min:1',
            ]);

            $question = $form->questions()->create($validated);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Added Form Question',
                'description' => "Added a new question to the form '{$form->name}'."
            ]);

            return response()->json(['message' => 'Question added successfully!', 'question' => $question], 201);
            
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Form not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Create Form Question Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.', 'debug_error' => $e->getMessage()], 500);
        }
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $question = FormQuestion::with('form')->whereHas('form', function ($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->where('id', $id)->firstOrFail();

            $validated = $request->validate([
                'section' => 'nullable|string|max:255',
                'instruction' => 'nullable|string',
                'text' => 'required|string',
                'type' => 'required|in:multiple_choice,short_answer',
                'choices' => 'nullable|array|max:10',
                'choices.*' => 'required|max:255',
                'correct_answer' => 'required|max:255',
                'points' => 'required|integer|min:1',
            ]);

            $question->update($validated);
            $formName = $question->form ? $question->form->name : 'Quiz/Exam';

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Form Question',
                'description' => "Updated a question inside the form '{$formName}'."
            ]);

            return response()->json(['message' => 'Question updated successfully!'], 200);
            
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Question not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Update Form Question Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.', 'debug_error' => $e->getMessage()], 500);
        }
    }

    // DELETE
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $question = FormQuestion::with('form')->whereHas('form', function ($query) use ($request) {
                $query->where('creator_id', $request->user()->id);
            })->where('id', $id)->firstOrFail();

            $formName = $question->form ? $question->form->name : 'Quiz/Exam';
            $question->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Form Question',
                'description' => "Deleted a question from the form '{$formName}'."
            ]);
            
            return response()->json(['message' => 'Question deleted.'], 200);
            
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Question not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Delete Form Question Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.', 'debug_error' => $e->getMessage()], 500);
        }
    }
}