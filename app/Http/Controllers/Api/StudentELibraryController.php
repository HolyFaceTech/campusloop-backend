<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ELibrary;
use Illuminate\Http\Request;

class StudentELibraryController extends Controller
{
    public function index()
    {
        try {
            // Kunin lang ang mga APPROVED na e-library materials
            $libraries = ELibrary::with(['creator', 'files'])
                ->where('status', 'approved')
                ->orderBy('created_at', 'desc')
                ->get();
                
            return response()->json($libraries, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch E-Library: ' . $e->getMessage()], 500);
        }
    }
}