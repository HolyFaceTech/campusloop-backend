<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Support\PublicFileStorage;
use Illuminate\Http\Request;

class FileViewController extends Controller
{
    public function viewUrl(Request $request, string $id)
    {
        $file = File::findOrFail($id);
        $storedPath = $file->getRawOriginal('path');
        $disposition = $request->query('disposition') === 'attachment' ? 'attachment' : 'inline';
        $url = PublicFileStorage::createSignedUrl(
            PublicFileStorage::relativePath($storedPath),
            null,
            $file->name,
            $file->file_extension,
            $disposition,
        ) ?? PublicFileStorage::urlForResponse($storedPath);

        if ($url === null || $url === '') {
            return response()->json(['message' => 'Could not generate a view URL for this file.'], 404);
        }

        return response()->json(['url' => $url], 200);
    }
}
