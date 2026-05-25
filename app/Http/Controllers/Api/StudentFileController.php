<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\File;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; 
use ZipArchive;

class StudentFileController extends Controller
{
    // RBAC
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // view files
    public function index(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $search = $request->input('search', '');
            $entries = (int) $request->input('entries', 12); 

            $query = File::where('owner_id', $studentId);

            if (!empty($search)) {
                $query->where('name', 'LIKE', "%{$search}%");
            }

            $files = $query->orderBy('created_at', 'desc')->paginate($entries);

            return response()->json($files, 200);

        } catch (\Exception $e) {
            Log::error('Student Fetch Files Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching files.'], 500);
        }
    }

    // download files
    public function downloadZip(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $request->validate([
                'file_ids' => 'required|array|max:20' 
            ]);

            $files = File::whereIn('id', $request->file_ids)
                ->where('owner_id', $request->user()->id)
                ->get();

            if ($files->isEmpty()) {
                return response()->json(['message' => 'No files found or unauthorized.'], 404);
            }

            $zip = new ZipArchive;
            $zipFileName = 'Student_Files_' . time() . '.zip';
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

            $count = $files->count();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Downloaded Files',
                'description' => "Downloaded a ZIP archive containing {$count} of your personal file(s)."
            ]);

            return response()->download($zipPath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Student Download ZIP Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while processing the ZIP file.'], 500);
        }
    }
}