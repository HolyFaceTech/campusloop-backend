<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\File;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class StudentFileController extends Controller
{
    // Kukunin lahat ng sariling files ng student
    public function index(Request $request)
    {
        try {
            $files = File::where('owner_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($files, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch files.'], 500);
        }
    }

    // Gagawa ng ZIP file para sa mga na-select na files at id-download
    public function downloadZip(Request $request)
    {
        $request->validate([
            'file_ids' => 'required|array'
        ]);

        $files = File::whereIn('id', $request->file_ids)
            ->where('owner_id', $request->user()->id)
            ->get();

        if ($files->isEmpty()) {
            return response()->json(['message' => 'No files found.'], 404);
        }

        $zip = new ZipArchive;
        $zipFileName = 'Student_Files_' . time() . '.zip';
        // Gagawa tayo ng temporary zip file sa public storage
        $zipPath = storage_path('app/public/' . $zipFileName);

        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($files as $file) {
                // Linisin ang path (Tatanggalin ang 'storage/' para makuha ang raw path sa public disk)
                $rawPath = str_replace('storage/', '', $file->path);
                $fullPath = storage_path('app/public/' . $rawPath);
                
                // Kapag nag-eexist yung file physically, idagdag sa ZIP
                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, $file->name);
                }
            }
            $zip->close();
        }

        $count = $files->count();

        // ACTIVITY LOG 
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Downloaded Files',
            'description' => "Downloaded a ZIP archive containing {$count} of your personal file(s)."
        ]);

        // I-return ang file at kusa itong ide-delete ng server pagkatapos ma-download (deleteFileAfterSend)
        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}