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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    // View Users
    public function index(Request $request)
    {
        $query = User::with('strand'); // I-load ang strand data 

        // Sorting by Role and Gender 
        if ($request->has('role') && $request->role != 'all') {
            $query->where('role', $request->role);
        }
        if ($request->has('gender') && $request->gender != 'all') {
            $query->where('gender', $request->gender);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get(), 200);
    }

    // Create Users
    public function store(Request $request)
    {
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

        $rawPassword = $request->password ? $request->password : Str::random(10);
        $validated['password'] = Hash::make($rawPassword);

        $validated['email_verified_at'] = null; 

        $user = User::create($validated);

        // Automatic na gagawan ng folder ang LAHAT ng users
        $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
        Storage::disk('public')->makeDirectory("users_files/{$folderName}");

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Created User',
            'description' => "Created a new {$user->role} account for {$user->first_name} {$user->last_name}."
        ]);

        // SEND WELCOME & VERIFICATION EMAIL
        $expires = now()->addHour()->timestamp;
        $hash = hash_hmac('sha256', $user->email . $expires, config('app.key'));
        $verifyLink = env('FRONTEND_URL') . '/verify?id=' . $user->id . '&hash=' . $hash . '&expires=' . $expires . '&email=' . urlencode($user->email);
        $loginLink = env('FRONTEND_URL') . '/login';

        Mail::send('emails.welcome_user', [
            'user' => $user,
            'rawPassword' => $rawPassword,
            'verifyLink' => $verifyLink,
            'loginLink' => $loginLink
        ], function($message) use($user) {
            $message->to($user->email);
            $message->subject('Welcome to CampusLoop - Your Account Details');
        });

        return response()->json(['message' => 'User created successfully!'], 201);
    }

    // Update Users
    public function update(Request $request, $id)
    {
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

        // FOLDER RENAME LOGIC
        // Kung may nagbago sa First Name o Last Name
        if (isset($dirtyAttributes['first_name']) || isset($dirtyAttributes['last_name'])) {
            $oldFolderName = str_replace(' ', '_', strtolower($originalUser->first_name . '_' . $originalUser->last_name . '_' . $originalUser->id));
            $newFolderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));

            // I-check kung nag-eexist yung lumang folder bago i-rename
            if (Storage::disk('public')->exists("users_files/{$oldFolderName}")) {
                Storage::disk('public')->move("users_files/{$oldFolderName}", "users_files/{$newFolderName}");
            } else {
                // Kung sakaling nawala o hindi nagawa dati, gawan ng bago
                Storage::disk('public')->makeDirectory("users_files/{$newFolderName}");
            }
        }

        // SET EMAIL NOTIFICATION LABELS
        $labels = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'gender' => 'Gender',
            'birthday' => 'Birthday',
            'email' => 'Email Address',
            'role' => 'Role',
            'status' => 'Account Status',
            'lrn' => 'LRN',
            'strand_id' => 'Strand ID'
        ];

        $changedFields = [];
        foreach ($dirtyAttributes as $key => $newValue) {
            if (array_key_exists($key, $labels) && $key !== 'password' && $key !== 'email_verified_at') {
                $changedFields[$labels[$key]] = [
                    'old' => $originalUser->{$key},
                    'new' => $newValue
                ];
            }
        }

        if ($request->filled('password')) {
            $changedFields['Password'] = [
                'old' => '********',
                'new' => $request->password 
            ];
        }

        if (count($changedFields) > 0) {
            Mail::send('emails.user_updated', [
                'user' => $user,
                'changedFields' => $changedFields
            ], function($message) use($user) {
                $message->to($user->email);
                $message->subject('CampusLoop - Account Information Updated');
            });
        }

        return response()->json(['message' => 'User updated successfully!'], 200);
    }

    // Delete User
    public function destroy(Request $request, $id)
    {
        if ($request->user()->id == $id) {
            return response()->json(['message' => 'Action denied. You cannot delete your own account.'], 403);
        }

        $user = User::findOrFail($id);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Deleted User',
            'description' => "Moved {$user->first_name} {$user->last_name} to the recycle bin."
        ]);

        $user->delete(); 
        return response()->json(['message' => 'User moved to recycle bin.'], 200);
    }

    // Delete Users
    public function bulkDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        
        $idsToDelete = array_filter($request->ids, function($id) use ($request) {
            return $id != $request->user()->id;
        });

        if (empty($idsToDelete)) {
            return response()->json(['message' => 'No valid users selected for deletion.'], 400);
        }

        $count = count($idsToDelete);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Bulk Deleted Users',
            'description' => "Moved {$count} selected users to the recycle bin."
        ]);

        User::whereIn('id', $idsToDelete)->delete(); 
        return response()->json(['message' => 'Selected users moved to recycle bin.'], 200);
    }

    // IMPORT CSV LOGIC
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv|max:5120', 
        ]);

        $file = $request->file('file');
        
        // Auto-detect line endings para sa ibat-ibang OS (Mac/Windows/Linux)
        ini_set('auto_detect_line_endings', true);

        $handle = fopen($file->getPathname(), "r");
        $header = fgetcsv($handle, 1000, ",");
        
        if (!$header) {
            return response()->json(['message' => 'The CSV file is empty or cannot be read.'], 400);
        }

        // Tanggalin ang BOM (Byte Order Mark) na idinadagdag ng Excel!
        $header[0] = preg_replace('/[\xef\xbb\xbf]/', '', $header[0]);
        
        $header = array_map('trim', $header);
        $header = array_map('strtolower', $header);

        $successCount = 0;
        $skippedCount = 0;

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // I-skip kung empty ang buong row (mga blank spaces sa ilalim ng Excel)
            if (empty(array_filter($data))) continue; 
            
            // Kung hindi pantay ang bilang ng column ng header at data, 
            // aayusin ito para hindi mag-error ang array_combine()
            if (count($header) !== count($data)) {
                $data = array_pad($data, count($header), '');
                $data = array_slice($data, 0, count($header));
            }
            
            $row = array_combine($header, $data);
            
            // Tanggalin ang extra spaces sa bawat text
            $row = array_map(function($value) {
                return trim($value ?? '');
            }, $row);

            // I-check kung may laman ang mga required fields
            if (empty($row['email']) || empty($row['first_name']) || empty($row['last_name'])) {
                continue;
            }

            // SKIP IF EMAIL OR LRN ALREADY EXISTS
            $query = User::where('email', $row['email']);
            if (!empty($row['lrn'])) {
                $query->orWhere('lrn', $row['lrn']);
            }
            if ($query->exists()) {
                $skippedCount++;
                continue; 
            }

            // CONVERT STRAND NAME TO STRAND ID (Case Insensitive)
            $strandId = null;
            $csvStrandName = $row['strand'] ?? $row['strand_name'] ?? null;
            
            if (!empty($csvStrandName)) {
                $strand = Strand::where('name', 'LIKE', $csvStrandName)->first();
                if ($strand) {
                    $strandId = $strand->id;
                }
            }

            // AUTO-GENERATE RANDOM SECURE PASSWORD
            $rawPassword = Str::random(12);

            $user = User::create([
                'first_name' => $row['first_name'],
                'last_name'  => $row['last_name'],
                'gender'     => !empty($row['gender']) ? $row['gender'] : 'Not Specified',
                'birthday'   => !empty($row['birthday']) ? date('Y-m-d', strtotime($row['birthday'])) : '2000-01-01',
                'email'      => $row['email'],
                'password'   => Hash::make($rawPassword),
                'role'       => strtolower($row['role'] ?? 'student'),
                'status'     => 'inactive',
                'lrn'        => $row['lrn'] ?? null,
                'strand_id'  => $strandId,
            ]);

            // Automatic na gagawan ng folder ang LAHAT ng users
            $folderName = str_replace(' ', '_', strtolower($user->first_name . '_' . $user->last_name . '_' . $user->id));
            Storage::disk('public')->makeDirectory("users_files/{$folderName}");

            // SEND WELCOME & VERIFICATION EMAIL 
            $expires = now()->addHour()->timestamp;
            $hash = hash_hmac('sha256', $user->email . $expires, config('app.key'));
            $verifyLink = env('FRONTEND_URL') . '/verify?id=' . $user->id . '&hash=' . $hash . '&expires=' . $expires . '&email=' . urlencode($user->email);
            $loginLink = env('FRONTEND_URL') . '/login';

            Mail::send('emails.welcome_user', [
                'user' => $user,
                'rawPassword' => $rawPassword,
                'verifyLink' => $verifyLink,
                'loginLink' => $loginLink
            ], function($message) use($user) {
                $message->to($user->email);
                $message->subject('Welcome to CampusLoop - Your Account Details');
            });

            $successCount++;
        }
        fclose($handle);

        $activityDesc = "Successfully imported {$successCount} new users from a CSV file.";
        if ($skippedCount > 0) {
            $activityDesc .= " Skipped {$skippedCount} duplicates.";
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Imported Users',
            'description' => $activityDesc
        ]);

        return response()->json([
            'message' => "Import complete! {$successCount} users created. {$skippedCount} skipped. Welcome emails sent."
        ], 200);
    }
}