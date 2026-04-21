<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Strand;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class StrandController extends Controller
{
    // View Strand
    public function index()
    {
        // Kukunin lahat ng strands + bibilangin kung ilang students ang naka-enroll
        $strands = Strand::withCount(['users' => function ($query) {
            $query->where('role', 'student');
        }])->orderBy('name', 'asc')->get();

        return response()->json($strands, 200);
    }

    // Create Strand
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:strands,name',
            'description' => 'required|string'
        ]);

        // Strand::create($validated);
        $strand = Strand::create($validated);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Created Strand',
            'description' => "Created a new academic strand: {$strand->name}."
        ]);

        return response()->json(['message' => 'Strand created successfully!'], 201);
    }

    // Update Strand
    public function update(Request $request, $id)
    {
        $strand = Strand::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:strands,name,' . $id,
            'description' => 'required|string'
        ]);

        $strand->update($validated);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Updated Strand',
            'description' => "Updated the details of academic strand: {$strand->name}."
        ]);

        return response()->json(['message' => 'Strand updated successfully!'], 200);
    }

    // Delete Strand
    public function destroy(Request $request, $id)
    {
        $strand = Strand::findOrFail($id);
        
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Deleted Strand',
            'description' => "Moved academic strand '{$strand->name}' to the recycle bin."
        ]);

        $strand->delete(); // Naka SoftDeletes ito
        return response()->json(['message' => 'Strand moved to recycle bin.'], 200);
    }
}