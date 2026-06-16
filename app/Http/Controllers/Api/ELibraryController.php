<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ELibrary;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB; 
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Support\PublicFileStorage;

class ELibraryController extends Controller
{
    // security
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // View ELibrary
    public function index(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $userId = $request->user()->id;

            $query = ELibrary::with(['creator', 'files'])
                ->where(function($q) use ($userId) {
                    $q->where('status', 'approved')
                      ->orWhere('creator_id', $userId);
                });

            if ($request->has('search') && $request->search !== '') {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
                });
            }

            $entries = $request->input('entries', 12);
            $libraries = $query->orderBy('created_at', 'desc')->paginate($entries);

            return response()->json($libraries, 200);

        } catch (\Throwable $e) {
            Log::error('Fetch ELibrary Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'Failed to fetch library resources.'], 500);
        }
    }

    // Create Elibrary
    public function store(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            if (! $request->hasFile('files')) {
                throw ValidationException::withMessages([
                    'files' => ['Please attach at least one PDF file.'],
                ]);
            }

            $uploadedFiles = PublicFileStorage::normalizeUploadedFiles($request->file('files'));

            if ($uploadedFiles === []) {
                throw ValidationException::withMessages([
                    'files' => ['Please attach at least one PDF file.'],
                ]);
            }

            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
            ]);

            if (count($uploadedFiles) > 5) {
                throw ValidationException::withMessages([
                    'files' => ['You can upload at most 5 files.'],
                ]);
            }

            foreach ($uploadedFiles as $file) {
                validator(
                    ['upload' => $file],
                    ['upload' => 'required|file|mimes:pdf|max:15360']
                )->validate();
            }

            $currentUser = $request->user();
            $teacherName = $currentUser->first_name . ' ' . $currentUser->last_name;
            $shortTitle = Str::limit($request->title, 30);

            DB::transaction(function () use ($request, $currentUser, $uploadedFiles) {
                $library = ELibrary::create([
                    'creator_id' => $currentUser->id,
                    'title' => $request->title,
                    'description' => $request->description,
                    'status' => 'pending',
                ]);

                foreach ($uploadedFiles as $file) {
                    $library->files()->create([
                        'owner_id' => $currentUser->id,
                        'name' => $file->getClientOriginalName(),
                        'path' => PublicFileStorage::storeUploaded($file, 'elibrary_files'),
                        'file_extension' => $file->getClientOriginalExtension(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            });

            try {
                $admins = User::where('role', 'admin')->get();

                foreach ($admins as $admin) {
                    DB::table('notifications')->insert([
                        'id' => Str::uuid()->toString(),
                        'user_id' => $admin->id,
                        'description' => "Teacher {$teacherName} uploaded a new material for approval: '{$shortTitle}'",
                        'link' => '/admin/e-libraries',
                        'is_read' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ActivityLog::create([
                    'user_id' => $currentUser->id,
                    'action' => 'Submitted E-Library Material',
                    'description' => "Uploaded a new material for approval: '{$shortTitle}'.",
                ]);
            } catch (\Exception $sideEffectError) {
                Log::warning('ELibrary store side-effect failed: '.$sideEffectError->getMessage());
            }

            return response()->json(['message' => 'Uploaded to Global Library. Pending Admin Approval.'], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Create ELibrary Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'An unexpected error occurred while saving.';

            return response()->json(['message' => $message], 500);
        }
    }

    // Update Elibrary
    public function update(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $library = ELibrary::where('creator_id', $request->user()->id)->findOrFail($id);

            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'files' => 'nullable|array|max:5',
                'files.*' => 'nullable|file|mimes:pdf|max:15360',
                'deleted_file_ids' => 'nullable|array',
            ]);

            $library->update([
                'title' => $request->title,
                'description' => $request->description,
                'status' => 'pending', 
                'admin_feedback' => null 
            ]);

            if ($request->has('deleted_file_ids')) {
                $filesToDelete = $library->files()->whereIn('id', $request->deleted_file_ids)->get();
                foreach ($filesToDelete as $f) {
                    PublicFileStorage::deleteStored($f->getRawOriginal('path'));
                    $f->delete();
                }
            }

            if ($request->hasFile('files')) {
                $uploadedFiles = PublicFileStorage::normalizeUploadedFiles($request->file('files'));

                foreach ($uploadedFiles as $file) {
                    validator(
                        ['upload' => $file],
                        ['upload' => 'required|file|mimes:pdf|max:15360']
                    )->validate();

                    $library->files()->create([
                        'owner_id' => $request->user()->id,
                        'name' => $file->getClientOriginalName(),
                        'path' => PublicFileStorage::storeUploaded($file, 'elibrary_files'),
                        'file_extension' => $file->getClientOriginalExtension(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $shortTitle = Str::limit($request->title, 30);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated E-Library Material',
                'description' => "Updated and re-submitted the material: '{$shortTitle}'."
            ]);

            return response()->json(['message' => 'Changes saved and re-submitted for approval.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Resource not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Update ELibrary Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while updating.'], 500);
        }
    }

    // Delete Elibrary
    public function destroy(Request $request, $id)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $library = ELibrary::where('creator_id', $request->user()->id)->findOrFail($id);
            $shortTitle = Str::limit($library->title, 30);
            $library->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted E-Library Material',
                'description' => "Moved the material '{$shortTitle}' to the recycle bin."
            ]);
            
            return response()->json(['message' => 'Moved to Recycle Bin.'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Resource not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Delete ELibrary Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while deleting.'], 500);
        }
    }
}