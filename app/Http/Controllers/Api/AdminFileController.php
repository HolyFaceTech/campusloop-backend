<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\File;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AdminFileController extends Controller
{
    // SECURITY FEATURE
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // FOLDERS
    public function folders(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = User::whereIn('role', ['teacher', 'student']);

            // SERVER-SIDE SEARCH
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$search}%")
                      ->orWhere('role', 'LIKE', "%{$search}%");
                });
            }

            $query->orderBy('role', 'asc')->orderBy('first_name', 'asc');

            // PAGINATION (Fixed at 12 entries per page para sa grid)
            $entries = $request->has('entries') ? (int) $request->entries : 12;
            $paginatedUsers = $query->paginate($entries);

            // KUNIN LANG ANG FILE COUNTS PARA SA MGA USERS NA NASA CURRENT PAGE
            $userIds = collect($paginatedUsers->items())->pluck('id');
            $fileCounts = File::whereIn('owner_id', $userIds)
                ->selectRaw('owner_id, count(*) as total')
                ->groupBy('owner_id')
                ->pluck('total', 'owner_id');

            $folders = collect($paginatedUsers->items())->map(function($user) use ($fileCounts) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'role' => $user->role,
                    'file_count' => $fileCounts[$user->id] ?? 0
                ];
            });

            // Ipakita lang ang System Announcements folder kung tugma sa search
            $page = $paginatedUsers->currentPage();
            $searchKeyword = $request->search ?? '';
            $matchSystem = empty($searchKeyword) || stripos('system announcements', $searchKeyword) !== false;

            if ($page === 1 && $matchSystem) {
                $announcementCount = File::where('attachable_type', 'like', '%Announcement%')->count();
                $folders->prepend([
                    'id' => 'system_announcements',
                    'name' => 'System Announcements',
                    'role' => 'system',
                    'file_count' => $announcementCount
                ]);
            }

            return response()->json([
                'data' => $folders,
                'total' => $paginatedUsers->total() + ($page === 1 && $matchSystem ? 1 : 0),
                'last_page' => $paginatedUsers->lastPage()
            ], 200);
        } catch (\Exception $e) {
            Log::error('AdminFileController folders Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while fetching directories.'], 500);
        }
    }

    // FILES
    public function userFiles(Request $request, $userId)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            // FOLDER TARGET
            if ($userId === 'system_announcements') {
                $query = File::where('attachable_type', 'like', '%Announcement%');
            } else {
                $query = File::where('owner_id', $userId);
            }

            // SERVER-SIDE SEARCH (File Name)
            if ($request->has('search') && !empty($request->search)) {
                $query->where('name', 'LIKE', "%{$request->search}%");
            }

            // SERVER-SIDE FILTER (File Type)
            if ($request->has('type') && $request->type !== 'all') {
                $type = $request->type;
                if ($type === 'Announcement') {
                    $query->where('attachable_type', 'like', '%Announcement%');
                } elseif ($type === 'Submission') {
                    $query->where('attachable_type', 'like', '%ClassworkSubmission%');
                } elseif ($type === 'Classwork') {
                    $query->where('attachable_type', 'like', '%Classwork%')
                          ->where('attachable_type', 'not like', '%Submission%');
                } elseif ($type === 'E-Library') {
                    $query->where('attachable_type', 'like', '%ELibrary%');
                } elseif ($type === 'Other') {
                    $query->whereNull('attachable_type');
                }
            }

            // SERVER-SIDE SORTING
            $sortOrder = $request->has('sort') && $request->sort === 'oldest' ? 'asc' : 'desc';
            $query->orderBy('created_at', $sortOrder);

            // PAGINATION (Fixed at 12 entries per page)
            $entries = $request->has('entries') ? (int) $request->entries : 12;
            $paginatedFiles = $query->paginate($entries);

            // MAP THE RESULTS TO APPEND SOURCE LABEL
            $files = collect($paginatedFiles->items())->map(function ($file) {
                $source = 'Other';
                $type = $file->attachable_type ?? '';
                
                if (str_contains($type, 'ELibrary')) {
                    $source = 'E-Library';
                } elseif (str_contains($type, 'ClassworkSubmission')) {
                    $source = 'Submission';
                } elseif (str_contains($type, 'Classwork')) {
                    $source = 'Classwork';
                } elseif (str_contains($type, 'Announcement')) {
                    $source = 'Announcement';
                }
                
                $file->source_label = $source;
                return $file;
            });

            return response()->json([
                'data' => $files,
                'total' => $paginatedFiles->total(),
                'last_page' => $paginatedFiles->lastPage()
            ], 200);
        } catch (\Exception $e) {
            Log::error('AdminFileController userFiles Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while fetching files.'], 500);
        }
    }

    // Gagawa ng ZIP file para sa mga na-select na files at id-download
    public function downloadZip(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }
        
        try {
            $request->validate([
                'file_ids' => 'required|array|max:50',
                'file_ids.*' => 'exists:files,id'
            ]);

            $files = File::whereIn('id', $request->file_ids)->get();

            if ($files->isEmpty()) {
                return response()->json(['message' => 'No files found in database.'], 404);
            }

            $zip = new ZipArchive;
            $zipFileName = 'Admin_Export_' . time() . '.zip';
            $zipPath = storage_path('app/public/' . $zipFileName);
            $hasFiles = false; 

            if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
                foreach ($files as $file) {
                    $rawPath = str_replace('storage/', '', $file->path);
                    $fullPath = storage_path('app/public/' . ltrim($rawPath, '/\\'));
                    
                    if (file_exists($fullPath)) {
                        $zip->addFile($fullPath, $file->name);
                        $hasFiles = true; 
                    }
                }
                $zip->close();
            }

            if (!$hasFiles || !file_exists($zipPath)) {
                return response()->json([
                    'message' => 'The selected files are missing from the server storage.'
                ], 404);
            }

            $count = count($request->file_ids);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Downloaded Files',
                'description' => "Downloaded a ZIP archive containing {$count} system file(s)."
            ]);

            return response()->download($zipPath)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            Log::error('AdminFileController downloadZip Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while creating the ZIP file.'], 500);
        }
    }

    // Soft delete para sa mga selected files
    public function bulkDelete(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $request->validate([
                'file_ids' => 'required|array|max:50',
                'file_ids.*' => 'exists:files,id'
            ]);

            DB::beginTransaction();

            // KUNIN ANG FILES KASAMA ANG OWNER BAGO BURAHIN
            $files = File::with('owner')->whereIn('id', $request->file_ids)->get();

            // Soft Delete
            File::whereIn('id', $request->file_ids)->delete();

            // NOTIFICATION LOGIC 
            $actor = $request->user();
            $actorName = $actor->first_name . ' ' . $actor->last_name;
            $actorRole = ucfirst($actor->role);

            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach ($files as $file) {
                $owner = $file->owner;
                
                if ($owner) {
                    $ownerRole = strtolower($owner->role); 
                    
                    // SMART LINKING: Kapag student, sa files tab. Kapag iba, sa recycle-bin.
                    if ($ownerRole === 'student') {
                        $link = "/student/files";
                    } else {
                        $link = "/{$ownerRole}/recycle-bin"; 
                    }

                    // SMART DESCRIPTION
                    if ($actor->id === $owner->id) {
                        $description = "You deleted your file '{$file->name}'. It was moved to the Recycle Bin.";
                    } else {
                        $description = "{$actorRole} {$actorName} deleted your file '{$file->name}'. It was moved to the Recycle Bin.";
                    }

                    $notifications[] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $owner->id,
                        'description' => $description,
                        'link' => $link,
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

            $count = count($request->file_ids);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'Deleted System Files',
                'description' => "Moved {$count} file(s) from the File Management system to the recycle bin."
            ]);

            DB::commit();
            return response()->json(['message' => 'Files moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminFileController bulkDelete Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while deleting files.'], 500);
        }
    }
}