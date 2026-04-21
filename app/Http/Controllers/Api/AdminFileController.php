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

class AdminFileController extends Controller
{
    // Kukunin ang lahat ng Users (Teacher & Student) at ang Virtual System Folders
    public function folders()
    {
        try {
            $users = User::whereIn('role', ['teacher', 'student'])
                ->orderBy('role')
                ->orderBy('first_name')
                ->get();
            
            // Bilangin ang files per owner
            $fileCounts = File::selectRaw('owner_id, count(*) as total')
                ->groupBy('owner_id')
                ->pluck('total', 'owner_id');

            $folders = $users->map(function($user) use ($fileCounts) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'role' => $user->role,
                    'file_count' => $fileCounts[$user->id] ?? 0
                ];
            });

            // GUMAWA NG VIRTUAL FOLDER PARA SA ANNOUNCEMENTS
            $announcementCount = File::where('attachable_type', 'like', '%Announcement%')->count();
            
            // Ilagay sa pinaka-unahan ang Announcement folder gamit ang prepend
            $folders->prepend([
                'id' => 'system_announcements', // Special ID para ma-detect sa userFiles
                'name' => 'System Announcements',
                'role' => 'system',
                'file_count' => $announcementCount
            ]);

            return response()->json($folders, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch folders.'], 500);
        }
    }

    // Kukunin ang laman ng folder ng specific user o ng system folder
    public function userFiles($userId)
    {
        try {
            // CHECK KUNG "SYSTEM ANNOUNCEMENTS" YUNG FOLDER NA BINUBUKSAN
            if ($userId === 'system_announcements') {
                $filesQuery = File::where('attachable_type', 'like', '%Announcement%');
            } else {
                $filesQuery = File::where('owner_id', $userId);
            }

            $files = $filesQuery->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($file) {
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

            return response()->json($files, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch files.'], 500);
        }
    }

    // Gagawa ng ZIP file para sa mga na-select na files at id-download
    public function downloadZip(Request $request)
    {
        $request->validate(['file_ids' => 'required|array']);

        $files = File::whereIn('id', $request->file_ids)->get();

        if ($files->isEmpty()) {
            return response()->json(['message' => 'No files found.'], 404);
        }

        $zip = new ZipArchive;
        $zipFileName = 'Admin_Export_' . time() . '.zip';
        $zipPath = storage_path('app/public/' . $zipFileName);

        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($files as $file) {
                $rawPath = str_replace('storage/', '', $file->path);
                $fullPath = storage_path('app/public/' . $rawPath);
                
                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, $file->name);
                }
            }
            $zip->close();
        }

        $count = count($request->file_ids);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Downloaded Files',
            'description' => "Downloaded a ZIP archive containing {$count} system file(s)."
        ]);

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    // Soft delete para sa mga selected files
    public function bulkDelete(Request $request)
    {
        try {
            $request->validate(['file_ids' => 'required|array']);

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

            return response()->json(['message' => 'Files moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}