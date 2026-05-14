<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\SystemSetting;
use App\Models\Subject; 
use App\Models\ActivityLog; 
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log; 
use Illuminate\Database\Eloquent\ModelNotFoundException; 

class ClassroomController extends Controller
{
    // RBAC HELPER
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // View Classrooms
   public function index(Request $request)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $search = $request->input('search');
            $entries = $request->input('entries', 12);

            $query = Classroom::with(['subject', 'strand', 'creator'])
                ->withCount(['students as enrolled_count' => function ($query) {
                    $query->where('classroom_student.status', 'approved')
                          ->whereNull('users.deleted_at');
                }])
                ->where('creator_id', $request->user()->id);

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('section', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhereHas('subject', function ($q2) use ($search) {
                          $q2->where('description', 'like', "%{$search}%")
                             ->orWhere('code', 'like', "%{$search}%");
                      });
                });
            }

            $classrooms = $query->orderBy('created_at', 'desc')->paginate($entries);

            return response()->json($classrooms, 200);
        } catch (\Exception $e) {
            Log::error('Fetch Classrooms Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching classrooms.'], 500);
        }
    }

    // Create Classroom
    public function store(Request $request)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $validated = $request->validate([
                'section' => 'required|string|max:255',
                'strand_id' => 'required|uuid|exists:strands,id',
                'grade_level' => 'required|in:11,12',
                'subject_id' => 'required|uuid|exists:subjects,id',
                'capacity' => 'required|integer|min:1',
                'color_bg' => 'required|string|max:20',
                'schedule' => 'required|array',
                'schedule.days' => 'required|array|min:1|max:7',
                'schedule.days.*' => 'required|string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                'schedule.start_time' => 'required|date_format:H:i',
                'schedule.end_time' => 'required|date_format:H:i|after:schedule.start_time',
            ]);

            $activeSetting = class_exists('\App\Models\SystemSetting') 
                ? SystemSetting::where('is_active', true)->first() 
                : null;

            do {
                $code = strtoupper(Str::random(3) . '-' . Str::random(3) . '-' . Str::random(3));
            } while (Classroom::where('code', $code)->exists());

            $classroom = Classroom::create([
                'creator_id' => $request->user()->id,
                'section' => $validated['section'],
                'strand_id' => $validated['strand_id'],
                'grade_level' => $validated['grade_level'],
                'subject_id' => $validated['subject_id'],
                'capacity' => $validated['capacity'],
                'schedule' => $validated['schedule'], 
                'color_bg' => $validated['color_bg'],
                'code' => $code,
                'school_year' => $activeSetting ? $activeSetting->school_year : 'Not Set',
                'semester' => $activeSetting ? $activeSetting->semester : '1st',
            ]);

            $subject = Subject::find($validated['subject_id']);
            $subjectName = $subject ? $subject->description : 'a subject';

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created Classroom',
                'description' => "Created a new classroom for {$subjectName} ({$validated['section']})."
            ]);

            return response()->json(['message' => 'Classroom created successfully!', 'code' => $code], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Create Classroom Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while creating the classroom.'], 500);
        }
    }

    // Inside Classroom
    public function show(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $classroom = Classroom::with(['subject', 'strand', 'creator'])
                ->withCount(['students as enrolled_count' => function ($query) {
                    $query->where('classroom_student.status', 'approved')
                          ->whereNull('users.deleted_at'); 
                }])
                ->where('creator_id', $request->user()->id)
                ->findOrFail($id);

            return response()->json($classroom, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Classroom not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Show Classroom Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    // Update Classroom
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $classroom = Classroom::where('creator_id', $request->user()->id)->findOrFail($id);
            $validated = $request->validate([
                'section' => 'required|string|max:255',
                'strand_id' => 'required|uuid|exists:strands,id',
                'grade_level' => 'required|in:11,12',
                'subject_id' => 'required|uuid|exists:subjects,id',
                'capacity' => 'required|integer|min:1',
                'color_bg' => 'required|string|max:20',
                'schedule' => 'required|array',
                'schedule.days' => 'required|array|min:1|max:7',
                'schedule.days.*' => 'required|string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                'schedule.start_time' => 'required|date_format:H:i',
                'schedule.end_time' => 'required|date_format:H:i|after:schedule.start_time',
            ]);

            $classroom->update($validated);
            $subject = Subject::find($validated['subject_id']);
            $subjectName = $subject ? $subject->description : 'a subject';

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Classroom',
                'description' => "Updated the details of classroom {$subjectName} ({$validated['section']})."
            ]);

            return response()->json(['message' => 'Classroom updated successfully!'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Classroom not found.'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Update Classroom Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while updating the classroom.'], 500);
        }
    }

    // Delete Classroom
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);

        try {
            $classroom = Classroom::where('creator_id', $request->user()->id)->findOrFail($id);
            $subjectName = $classroom->subject ? $classroom->subject->description : 'a subject';
            $sectionName = $classroom->section;
            $classroom->delete(); 

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Classroom',
                'description' => "Moved the classroom {$subjectName} ({$sectionName}) to the recycle bin."
            ]);

            return response()->json(['message' => 'Classroom moved to recycle bin.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Classroom not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Delete Classroom Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting the classroom.'], 500);
        }
    }
}