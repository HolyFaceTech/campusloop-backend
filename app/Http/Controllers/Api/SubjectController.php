<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Strand;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Facades\Log; 

class SubjectController extends Controller
{
    // SECURITY 
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // View Subject
    public function index(Request $request)
    {
        try {
            $query = Subject::with('strand');

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            if ($request->has('filterStrand') && $request->filterStrand !== 'all') {
                $query->where('strand_id', $request->filterStrand);
            }

            if ($request->has('filterGrade') && $request->filterGrade !== 'all') {
                $query->where('grade_level', $request->filterGrade);
            }

            if ($request->has('filterSemester') && $request->filterSemester !== 'all') {
                $query->where('semester', $request->filterSemester);
            }

            $query->orderBy('created_at', 'desc');
            $entries = $request->has('entries') ? (int) $request->entries : 10;
            
            return response()->json($query->paginate($entries), 200);

        } catch (\Exception $e) {
            Log::error('SubjectController index Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching subjects.'], 500);
        }
    }

    // Create Subject
    public function store(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:subjects,code',
            'description' => 'required|string',
            'strand_id' => 'required|exists:strands,id',
            'grade_level' => 'required|in:11,12',
            'semester' => 'required|in:1st,2nd'  
        ]);

        try {
            DB::beginTransaction();

            $subject = Subject::create($validated);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created Subject',
                'description' => "Created a new subject: {$subject->code} - {$subject->description}."
            ]);

            DB::commit();
            return response()->json(['message' => 'Subject created successfully!'], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SubjectController store Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while creating the subject.'], 500);
        }
    }

    // Update Subject
    public function update(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:subjects,code,' . $id,
            'description' => 'required|string',
            'strand_id' => 'required|exists:strands,id',
            'grade_level' => 'required|in:11,12',
            'semester' => 'required|in:1st,2nd'
        ]);

        try {
            DB::beginTransaction();

            $subject = Subject::findOrFail($id);
            $subject->update($validated);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Subject',
                'description' => "Updated the details of subject: {$subject->code}."
            ]);

            DB::commit();
            return response()->json(['message' => 'Subject updated successfully!'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SubjectController update Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while updating the subject.'], 500);
        }
    }

    // Delete Subject
    public function destroy(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            DB::beginTransaction();
            $subject = Subject::findOrFail($id);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Subject',
                'description' => "Moved subject '{$subject->code}' to the recycle bin."
            ]);

            $subject->delete();

            DB::commit();
            return response()->json(['message' => 'Subject moved to recycle bin.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SubjectController destroy Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting the subject.'], 500);
        }
    }

    // Para sa Multiple Deletion 
    public function bulkDelete(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $request->validate(['ids' => 'required|array|max:100']);

        try {
            DB::beginTransaction();
            $count = count($request->ids);

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Bulk Deleted Subjects',
                    'description' => "Moved {$count} selected subjects to the recycle bin."
                ]);
            }

            Subject::whereIn('id', $request->ids)->delete();

            DB::commit();
            return response()->json(['message' => 'Selected subjects moved to recycle bin.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SubjectController bulkDelete Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting subjects.'], 500);
        }
    }

    // IMPORT CSV
    public function import(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120', 
        ]);

        try {
            ini_set('auto_detect_line_endings', true);
            $file = $request->file('file');
            $handle = fopen($file->getPathname(), "r");
            $header = fgetcsv($handle, 1000, ",");
            
            if (!$header) {
                return response()->json(['message' => 'The CSV file is empty or cannot be read.'], 400);
            }

            // Remove BOM and clean headers
            $header[0] = preg_replace('/[\xef\xbb\xbf]/', '', $header[0]);
            $header = array_map('trim', $header);
            $header = array_map('strtolower', $header);
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
                    if (preg_match('/^[=\+\-\@]/', $value)) {
                        $value = "'" . $value; 
                    }
                    return $value;
                }, $row);

                // Required Columns Check
                if (empty($row['code']) || empty($row['description']) || empty($row['strand']) || empty($row['grade_level']) || empty($row['semester'])) {
                    $skippedCount++;
                    continue;
                }

                // Skip existing subject code
                if (Subject::where('code', $row['code'])->exists()) {
                    $skippedCount++;
                    continue; 
                }

                DB::beginTransaction();
                try {
                    // Find Strand ID by Strand Name
                    $strandName = $row['strand'];
                    $strand = Strand::where('name', 'LIKE', $strandName)->first();
                    
                    if (!$strand) {
                        DB::rollBack();
                        $skippedCount++; // Kung hindi nag-e-exist ang strand, i-skip ito
                        continue;
                    }

                    // SEMESTER VALIDATION 
                    $rawSem = strtolower($row['semester']);
                    $validSem = '1st'; // Default fallback
                    if (str_contains($rawSem, '2') || str_contains($rawSem, 'nd')) {
                        $validSem = '2nd';
                    }

                    // GRADE LEVEL VALIDATION
                    $rawGrade = strtolower($row['grade_level']);
                    $validGrade = '11'; // Default fallback
                    if (str_contains($rawGrade, '12')) {
                        $validGrade = '12';
                    }

                    Subject::create([
                        'code'        => strtoupper($row['code']),
                        'description' => $row['description'],
                        'strand_id'   => $strand->id,
                        'grade_level' => $validGrade,
                        'semester'    => $validSem,
                    ]);

                    DB::commit(); 
                    $successCount++;

                } catch (\Exception $e) {
                    DB::rollBack(); 
                    Log::error('SubjectController CSV row Error: ' . $e->getMessage());
                    $skippedCount++;
                }
            }
            fclose($handle);

            $activityDesc = "Successfully imported {$successCount} new subjects from a CSV file.";
            if ($skippedCount > 0) {
                $activityDesc .= " Skipped {$skippedCount} duplicates/errors.";
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Imported Subjects',
                'description' => $activityDesc
            ]);

            return response()->json([
                'message' => "Import complete! {$successCount} subjects created. {$skippedCount} skipped."
            ], 200);

        } catch (\Exception $e) {
            Log::error('SubjectController import Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while importing the file.'], 500);
        }
    }
}