<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FinalGrade;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log; 

class StudentGradeController extends Controller
{
    // RBAC 
    private function checkStudent(Request $request)
    {
        return $request->user() && $request->user()->role === 'student';
    }

    // View Student Final Grade 
    public function index(Request $request)
    {
        if (!$this->checkStudent($request)) {
            return response()->json(['message' => 'Unauthorized Access. Students only.'], 403);
        }

        try {
            $studentId = $request->user()->id;
            $search = $request->input('search', '');
            $entries = (int) $request->input('entries', 10);
            $sy = $request->input('sy', 'all');
            $sem = $request->input('sem', 'all');
            $activeSetting = SystemSetting::where('is_active', true)->first();

            $uniqueSchoolYears = FinalGrade::where('student_id', $studentId)
                ->whereIn('status', ['locked', 'approved'])
                ->distinct()
                ->pluck('school_year')
                ->filter()
                ->values()
                ->all();

            if ($activeSetting && $activeSetting->school_year && !in_array($activeSetting->school_year, $uniqueSchoolYears)) {
                array_unshift($uniqueSchoolYears, $activeSetting->school_year);
            }

            $query = FinalGrade::with(['subject'])
                ->where('student_id', $studentId)
                ->whereIn('status', ['locked', 'approved']);

            if (!empty($search)) {
                $query->whereHas('subject', function($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            if ($sy !== 'all') {
                $query->where('school_year', $sy);
            }

            if ($sem !== 'all') {
                if (in_array($sem, ['1st', '2nd'])) {
                    $query->where(function($q) use ($sem) {
                        $q->where('semester', $sem)
                          ->orWhere('semester', "{$sem} Sem")
                          ->orWhere('semester', "{$sem} Semester");
                    });
                } else {
                    $query->where('semester', $sem);
                }
            }

            $grades = $query->orderBy('school_year', 'desc')
                            ->orderBy('semester', 'desc')
                            ->paginate($entries);

            $grades->getCollection()->transform(function ($finalGrade) {
                return [
                    'id' => $finalGrade->id,
                    'grade' => $finalGrade->grade,
                    'school_year' => $finalGrade->school_year ?? 'N/A',
                    'semester' => $finalGrade->semester ?? 'N/A',
                    'subject_code' => $finalGrade->subject->code ?? 'N/A',
                    'subject_description' => $finalGrade->subject->description ?? 'N/A',
                    'date_locked' => $finalGrade->updated_at,
                ];
            });

            return response()->json([
                'active_setting' => $activeSetting,
                'unique_school_years' => $uniqueSchoolYears,
                'grades' => $grades
            ], 200);

        } catch (\Exception $e) {
            Log::error('Student Grades Fetch Error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while fetching your grades.'], 500);
        }
    }
}