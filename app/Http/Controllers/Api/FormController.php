<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    // RBAC 
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // View Form (WITH SERVER-SIDE PAGINATION & SEARCH)
    public function index(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $search = $request->input('search');
            $entries = $request->input('entries', 12);

            $query = Form::with('creator')
                ->where('creator_id', $request->user()->id);

            // I-apply ang Search Filter
            if (!empty($search)) {
                $query->where('name', 'like', "%{$search}%");
            }

            // Gamitin ang Paginate imbes na get()
            $forms = $query->orderBy('created_at', 'desc')->paginate($entries);
            
            return response()->json($forms, 200);
        } catch (\Exception $e) {
            Log::error('Fetch Forms Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching forms.'], 500);
        }
    }

    // Create Form
    public function store(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'instruction' => 'required|string',
                'timer' => 'nullable|numeric|min:0',
                'is_shuffle_questions' => 'boolean',
                'is_focus_mode' => 'boolean',
            ]);
            $validated['timer'] = $validated['timer'] ?? 0;
            $validated['is_shuffle_questions'] = filter_var($validated['is_shuffle_questions'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $validated['is_focus_mode'] = filter_var($validated['is_focus_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $form = Form::create(array_merge($validated, ['creator_id' => $request->user()->id]));

            // ACTIVITY LOG
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created Form',
                'description' => "Created a new quiz/exam form: '{$form->name}'."
            ]);

            return response()->json(['message' => 'Form setup saved! Redirecting to builder...', 'form' => $form], 201);
        } catch (\Exception $e) {
            Log::error('Create Form Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while creating the form.'], 500);
        }
    }

    // Update Form
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $form = Form::where('creator_id', $request->user()->id)->findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'instruction' => 'required|string',
                'timer' => 'nullable|numeric|min:0',
                'is_shuffle_questions' => 'boolean',
                'is_focus_mode' => 'boolean',
            ]);
            $validated['timer'] = $validated['timer'] ?? 0;
            $validated['is_shuffle_questions'] = filter_var($validated['is_shuffle_questions'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $validated['is_focus_mode'] = filter_var($validated['is_focus_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $form->update($validated);

            // ACTIVITY LOG
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Form',
                'description' => "Updated the details and settings of the form: '{$form->name}'."
            ]);
            
            return response()->json(['message' => 'Form details updated successfully!'], 200);

        } catch (\Exception $e) {
            Log::error('Update Form Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while updating the form.'], 500);
        }
    }

    // Delete Form
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $form = Form::where('creator_id', $request->user()->id)->findOrFail($id);
            $formName = $form->name;
            $form->delete();

            // ACTIVITY LOG
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Form',
                'description' => "Moved the form '{$formName}' to the recycle bin."
            ]);

            return response()->json(['message' => 'Form moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            Log::error('Delete Form Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting the form.'], 500);
        }
    }

    // Duplicate Form
    public function duplicate(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            // Kasama na ang questions na ife-fetch para maduplicate
            $original = Form::with('questions')->where('creator_id', $request->user()->id)->findOrFail($id);
            
            $baseName = trim(preg_replace('/\(\d+\)$/', '', $original->name));
            $count = Form::where('creator_id', $request->user()->id)
                ->where(function ($query) use ($baseName) {
                    $query->where('name', $baseName)
                          ->orWhere('name', 'LIKE', $baseName . ' (%)');
                })->count();

            // I-duplicate ang Form details
            $newForm = $original->replicate();
            $newForm->name = $baseName . ' (' . ($count > 0 ? $count : 1) . ')';
            $newForm->duplicate_from_id = $original->id;
            $newForm->save();

            // I-duplicate lahat ng questions papunta sa bagong form (Walang respondents)
            foreach ($original->questions as $question) {
                $newQuestion = $question->replicate();
                $newQuestion->form_id = $newForm->id; // I-assign sa bagong Form ID
                $newQuestion->save();
            }

            // ACTIVITY LOG
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Duplicated Form',
                'description' => "Duplicated the form '{$original->name}' to create '{$newForm->name}'."
            ]);

            return response()->json(['message' => 'Form duplicated successfully!', 'form' => $newForm], 201);
        } catch (\Exception $e) {
            Log::error('Duplicate Form Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while duplicating the form.'], 500);
        }
    }

    // View Inside of Form
    public function show(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $form = Form::with(['questions' => function ($query) {
                $query->orderBy('created_at', 'asc');
            }])->where('creator_id', $request->user()->id)->findOrFail($id);

            return response()->json($form, 200);
        } catch (\Exception $e) {
            Log::error('Show Form Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while loading the form.'], 500);
        }
    }

    // Student Submission
    public function respondents(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            Form::where('creator_id', $request->user()->id)->findOrFail($id);

            $submissions = \App\Models\FormSubmission::with(['student.strand'])
                ->where('form_id', $id)
                ->orderBy('submitted_at', 'desc')
                ->get();

            // Kunin lahat ng isinagot ng student at i-attach sa submission data
            $submissionIds = $submissions->pluck('id');
            $answers = DB::table('form_submission_answers')
                ->whereIn('submission_id', $submissionIds)
                ->get()
                ->groupBy('submission_id');

            foreach ($submissions as $submission) {
                $submission->answers = isset($answers[$submission->id]) ? $answers[$submission->id] : [];
            }

            return response()->json($submissions, 200);
        } catch (\Exception $e) {
            Log::error('Fetch Respondents Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching respondents.'], 500);
        }
    }
}