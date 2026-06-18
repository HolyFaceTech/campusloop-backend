<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Strand;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    // security
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    private function normalizeCsvHeader(array $header): array
    {
        return array_map(function ($col) {
            $col = preg_replace('/[\xef\xbb\xbf]/', '', (string) $col);
            $col = strtolower(trim($col));
            $col = preg_replace('/\s+/', '_', $col);

            return $col;
        }, $header);
    }

    private function resolveStrandFromCsvValue(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $strand = Strand::find($value);
        if ($strand) {
            return $strand->id;
        }

        $strand = Strand::whereRaw('LOWER(name) = ?', [strtolower($value)])->first();
        if ($strand) {
            return $strand->id;
        }

        $strand = Strand::where('name', 'LIKE', '%' . $value . '%')->first();
        if ($strand) {
            return $strand->id;
        }

        return null;
    }

    private function csvStrandValue(array $row): ?string
    {
        foreach (['strand_id', 'strand_name', 'strand', 'academic_strand', 'strand_code'] as $key) {
            if (!empty($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function ensureUserFolder(User $user): void
    {
        try {
            $folderName = Str::slug($user->first_name . '-' . $user->last_name . '-' . $user->id);
            $path = "users_files/{$folderName}";

            if (!Storage::disk('public')->exists($path)) {
                Storage::disk('public')->makeDirectory($path);
            }
        } catch (\Exception $e) {
            Log::warning('UserController ensureUserFolder: ' . $e->getMessage());
        }
    }

    private function renameUserFolder(User $originalUser, User $user): void
    {
        try {
            $oldFolderName = Str::slug($originalUser->first_name . '-' . $originalUser->last_name . '-' . $originalUser->id);
            $newFolderName = Str::slug($user->first_name . '-' . $user->last_name . '-' . $user->id);

            if ($oldFolderName === $newFolderName) {
                return;
            }

            $oldPath = "users_files/{$oldFolderName}";
            $newPath = "users_files/{$newFolderName}";

            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->move($oldPath, $newPath);
            } elseif (!Storage::disk('public')->exists($newPath)) {
                Storage::disk('public')->makeDirectory($newPath);
            }
        } catch (\Exception $e) {
            Log::warning('UserController renameUserFolder: ' . $e->getMessage());
        }
    }

    // view all users
    public function index(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = User::with('strand'); 

            if ($request->has('role') && $request->role != 'all') {
                $query->where('role', $request->role);
            }

            if ($request->has('gender') && $request->gender != 'all') {
                $query->where('gender', $request->gender);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('lrn', 'LIKE', "%{$search}%");
                });
            }

            $entries = $request->has('entries') ? (int) $request->entries : 10;
            
            return response()->json($query->orderBy('created_at', 'desc')->paginate($entries), 200);

        } catch (\Exception $e) {
            Log::error('UserController index Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching users.'], 500);
        }
    }

    // Create Users
    public function store(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|string',
            'birthday' => 'required|date',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:admin,teacher,student',
            'status' => 'required|in:active,inactive',
            'lrn' => 'required_if:role,student|nullable|unique:users,lrn',
            'strand_id' => 'required_if:role,student|nullable|exists:strands,id',
            'password' => ['nullable', 'string', Password::min(8)->letters()->mixedCase()->numbers()->symbols()]
        ]);

        try {
            DB::beginTransaction();

            $rawPassword = $request->password ? $request->password : Str::random(10);
            $validated['password'] = Hash::make($rawPassword);
            $validated['email_verified_at'] = null; 
            $user = User::create($validated);
            $this->ensureUserFolder($user);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created User',
                'description' => "Created a new {$user->role} account for {$user->first_name} {$user->last_name}."
            ]);

            $expires = now()->addHour()->timestamp;
            $hash = hash_hmac('sha256', $user->email . $expires, config('app.key'));
            $verifyLink = env('FRONTEND_URL') . '/verify?id=' . $user->id . '&hash=' . $hash . '&expires=' . $expires . '&email=' . urlencode($user->email);
            $loginLink = env('FRONTEND_URL') . '/login';

            dispatch(function () use ($user, $rawPassword, $verifyLink, $loginLink) {
                Mail::send('emails.welcome_user', [
                    'user' => $user,
                    'rawPassword' => $rawPassword,
                    'verifyLink' => $verifyLink,
                    'loginLink' => $loginLink
                ], function($message) use($user) {
                    $message->to($user->email);
                    $message->subject('Welcome to CampusLoop - Your Account Details');
                });
            });

            DB::commit(); 
            return response()->json(['message' => 'User created successfully!'], 201);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('UserController store Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while creating the user.'], 500);
        }
    }

    // Update Users
    public function update(Request $request, string $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $user = User::findOrFail($id);
        $originalUser = clone $user;

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|string',
            'birthday' => 'required|date',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => 'required|in:admin,teacher,student',
            'status' => 'required|in:active,inactive',
            'lrn' => ['required_if:role,student', 'nullable', Rule::unique('users')->ignore($user->id)],
            'strand_id' => 'required_if:role,student|nullable|exists:strands,id',
            'password' => ['nullable', 'string', Password::min(8)->letters()->mixedCase()->numbers()->symbols()]
        ]);

        try {
            DB::beginTransaction();

            if ($request->filled('password')) {
                $validated['password'] = Hash::make($request->password);
            } else {
                unset($validated['password']);
            }

            if ($request->status === 'inactive') {
                $validated['email_verified_at'] = null;
            } elseif ($request->status === 'active' && is_null($user->email_verified_at)) {
                $validated['email_verified_at'] = now();
            }

            $user->fill($validated);
            $dirtyAttributes = $user->getDirty(); 
            $user->save();

            if (count($dirtyAttributes) > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Updated User',
                    'description' => "Updated the account records of {$user->first_name} {$user->last_name}."
                ]);
            }

            if (isset($dirtyAttributes['first_name']) || isset($dirtyAttributes['last_name'])) {
                $this->renameUserFolder($originalUser, $user);
            }

            $labels = [
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'gender' => 'Gender',
                'birthday' => 'Birthday',
                'email' => 'Email Address',
                'role' => 'Role',
                'status' => 'Account Status',
                'lrn' => 'LRN',
                'strand_id' => 'Academic Strand'
            ];

            $changedFields = [];
            foreach ($dirtyAttributes as $key => $newValue) {
                if (array_key_exists($key, $labels) && $key !== 'password' && $key !== 'email_verified_at') {
                    
                    $oldValue = $originalUser->{$key};
                    $newVal = $newValue;

                    if ($key === 'strand_id') {
                        $oldValue = $oldValue ? (Strand::find($oldValue)?->name ?? 'None') : 'None';
                        $newVal = $newVal ? (Strand::find($newVal)?->name ?? 'None') : 'None';
                    }

                    $changedFields[$labels[$key]] = [
                        'old' => $oldValue,
                        'new' => $newVal
                    ];
                }
            }

            if ($request->filled('password')) {
                $changedFields['Password'] = [
                    'old' => '********',
                    'new' => $request->password 
                ];
            }

            DB::commit();

            if (count($changedFields) > 0) {
                dispatch(function () use ($user, $changedFields) {
                    Mail::send('emails.user_updated', [
                        'user' => $user,
                        'changedFields' => $changedFields
                    ], function($message) use($user) {
                        $message->to($user->email);
                        $message->subject('CampusLoop - Account Information Updated');
                    });
                });
            }

            return response()->json(['message' => 'User updated successfully!'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UserController update Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while updating the user.'], 500);
        }
    }

    // Delete User
    public function destroy(Request $request, string $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        if ($request->user()->id == $id) {
            return response()->json(['message' => 'Action denied. You cannot delete your own account.'], 403);
        }

        try {
            DB::beginTransaction();
            $user = User::findOrFail($id);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted User',
                'description' => "Moved {$user->first_name} {$user->last_name} to the recycle bin."
            ]);

            $user->delete(); 

            DB::commit();
            return response()->json(['message' => 'User moved to recycle bin.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UserController destroy Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting the user.'], 500);
        }
    }

    // Bulk Delete Users
    public function bulkDestroy(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $request->validate(['ids' => 'required|array']);
        
        $idsToDelete = array_filter($request->ids, function($id) use ($request) {
            return $id != $request->user()->id;
        });

        if (empty($idsToDelete)) {
            return response()->json(['message' => 'No valid users selected for deletion.'], 400);
        }

        try {
            DB::beginTransaction();

            $count = count($idsToDelete);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Bulk Deleted Users',
                'description' => "Moved {$count} selected users to the recycle bin."
            ]);

            User::whereIn('id', $idsToDelete)->delete(); 

            DB::commit();
            return response()->json(['message' => 'Selected users moved to recycle bin.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UserController bulkDestroy Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting users.'], 500);
        }
    }

    // IMPORT CSV 
    public function import(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv|max:5120', 
        ]);

        try {
            ini_set('auto_detect_line_endings', true);
            $file = $request->file('file');
            $handle = fopen($file->getPathname(), "r");
            $header = fgetcsv($handle, 1000, ",");
            
            if (!$header) {
                return response()->json(['message' => 'The CSV file is empty or cannot be read.'], 400);
            }

            $header = $this->normalizeCsvHeader($header);
            $successCount = 0;
            $skippedCount = 0;

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (empty(array_filter($data))) continue; 
                
                if (count($header) !== count($data)) {
                    $data = array_pad($data, count($header), '');
                    $data = array_slice($data, 0, count($header));
                }
                
                $row = array_combine($header, $data);
                
                // CSV INJECTION SANITIZATION
                $row = array_map(function($value) {
                    $value = trim($value ?? '');
                    // Harangin ang mga formulas (=, +, -, @)
                    if (preg_match('/^[=\+\-\@]/', $value)) {
                        $value = "'" . $value; 
                    }
                    return $value;
                }, $row);

                if (empty($row['email']) || empty($row['first_name']) || empty($row['last_name'])) {
                    $skippedCount++;
                    continue;
                }

                // Skip existing users
                $query = User::where('email', $row['email']);
                if (!empty($row['lrn'])) {
                    $query->orWhere('lrn', $row['lrn']);
                }
                if ($query->exists()) {
                    $skippedCount++;
                    continue; 
                }

                // HALF-FAILED IMPORTS (Row-Level Transaction)
                DB::beginTransaction();

                try {
                    $role = strtolower($row['role'] ?? 'student');
                    $csvStrandValue = $this->csvStrandValue($row);
                    $strandId = null;

                    if ($csvStrandValue !== null) {
                        $strandId = $this->resolveStrandFromCsvValue($csvStrandValue);

                        if (!$strandId) {
                            DB::rollBack();
                            $skippedCount++;
                            continue;
                        }
                    } elseif ($role === 'student' && !empty($row['lrn'])) {
                        DB::rollBack();
                        $skippedCount++;
                        continue;
                    }

                    $rawPassword = Str::random(12);

                    $user = User::create([
                        'first_name' => $row['first_name'],
                        'last_name'  => $row['last_name'],
                        'gender'     => !empty($row['gender']) ? $row['gender'] : 'Not Specified',
                        'birthday'   => !empty($row['birthday']) ? date('Y-m-d', strtotime($row['birthday'])) : '2000-01-01',
                        'email'      => $row['email'],
                        'password'   => Hash::make($rawPassword),
                        'role'       => $role,
                        'status'     => 'inactive',
                        'lrn'        => $role === 'student' ? ($row['lrn'] ?? null) : null,
                        'strand_id'  => $role === 'student' ? $strandId : null,
                    ]);

                    $this->ensureUserFolder($user);

                    $expires = now()->addHour()->timestamp;
                    $hash = hash_hmac('sha256', $user->email . $expires, config('app.key'));
                    $verifyLink = env('FRONTEND_URL') . '/verify?id=' . $user->id . '&hash=' . $hash . '&expires=' . $expires . '&email=' . urlencode($user->email);
                    $loginLink = env('FRONTEND_URL') . '/login';

                    dispatch(function () use ($user, $rawPassword, $verifyLink, $loginLink) {
                        Mail::send('emails.welcome_user', [
                            'user' => $user,
                            'rawPassword' => $rawPassword,
                            'verifyLink' => $verifyLink,
                            'loginLink' => $loginLink
                        ], function($message) use($user) {
                            $message->to($user->email);
                            $message->subject('Welcome to CampusLoop - Your Account Details');
                        });
                    });

                    DB::commit(); 
                    $successCount++;

                } catch (\Exception $e) {
                    DB::rollBack(); 
                    $skippedCount++;
                }
            }

            fclose($handle);

            $activityDesc = "Successfully imported {$successCount} new users from a CSV file.";
            if ($skippedCount > 0) {
                $activityDesc .= " Skipped {$skippedCount} duplicates/errors.";
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Imported Users',
                'description' => $activityDesc
            ]);

            return response()->json([
                'message' => "Import complete! {$successCount} users created. {$skippedCount} skipped."
            ], 200);

        } catch (\Exception $e) {
            Log::error('UserController import Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while importing the file.'], 500);
        }
    }
}