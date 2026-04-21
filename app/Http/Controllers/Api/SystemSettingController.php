<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Strand;
use App\Models\Subject;
use App\Models\Classroom;
use App\Models\AdvisoryClass;
use App\Models\Classwork;
use App\Models\Form;
use App\Models\Announcement;
use App\Models\File;
use App\Models\ELibrary;
use App\Models\ActivityLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

class SystemSettingController extends Controller
{
    // View School Setting
    public function index()
    {
        $activeSetting = SystemSetting::where('is_active', true)->first();
        return response()->json($activeSetting, 200);
    }

    // Set School Setting
    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => ['required', 'in:1st,2nd'] 
        ], [
            'semester.in' => 'Invalid semester selected. It must be either 1st or 2nd.'
        ]);

        SystemSetting::where('is_active', true)->update(['is_active' => false]);

        $newSetting = SystemSetting::create([
            'school_year' => $validated['school_year'],
            'semester' => $validated['semester'],
            'maintenance_mode' => false,
            'is_active' => true
        ]);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Updated System Settings',
            'description' => "Set the active School Year to {$newSetting->school_year} and Semester to {$newSetting->semester} Semester."
        ]);

        return response()->json([
            'message' => 'School Settings successfully updated!',
            'setting' => $newSetting
        ], 200);
    }

    // Reset School Setting
    public function reset(Request $request)
    {
        $activeSetting = SystemSetting::where('is_active', true)->first();
        
        if ($activeSetting) {
            $activeSetting->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Reset System Settings',
                'description' => "Cleared the active School Year and Semester configurations."
            ]);
        }

        return response()->json(['message' => 'School settings have been completely reset.'], 200);
    }

    // PDF REPORT GENERATOR FUNCTION
    public function generateReport()
    {
        try {
            // Kukunin ang current user na nag-click ng print
            $user = Auth::user();
            $generatorName = $user ? $user->first_name . ' ' . $user->last_name : 'Administrator';

            // Get Settings
            $activeSetting = SystemSetting::where('is_active', true)->first();

            // Get Users Stats
            $users = [
                'students_active' => User::where('role', 'student')->where('status', 'active')->count(),
                'students_inactive' => User::where('role', 'student')->where('status', 'inactive')->count(),
                'teachers_active' => User::where('role', 'teacher')->where('status', 'active')->count(),
                'teachers_inactive' => User::where('role', 'teacher')->where('status', 'inactive')->count(),
                'admins_active' => User::where('role', 'admin')->where('status', 'active')->count(),
                'admins_inactive' => User::where('role', 'admin')->where('status', 'inactive')->count(),
            ];

            // Get Strands & Demographics
            $strands = Strand::withCount(['users' => function($query) {
                $query->where('role', 'student');
            }])->get();

            // Academic Setup
            $academics = [
                'subjects' => Subject::count(),
                'classrooms' => Classroom::count(),
                'advisories' => AdvisoryClass::count(),
            ];

            // Engagement Metrics
            $engagement = [
                'classworks' => Classwork::count(),
                'forms' => Form::count(),
                'announcements' => Announcement::count(),
                'files' => File::count(),
                'elibrary' => ELibrary::where('status', 'approved')->count(),
            ];

            // Active Teachers Data
            $teachers = User::where('role', 'teacher')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->get()
                ->map(function ($t) {
                    return [
                        'name' => $t->first_name . ' ' . $t->last_name,
                        'classrooms_count' => Classroom::where('creator_id', $t->id)->count(),
                        'forms_count' => Form::where('creator_id', $t->id)->count(),
                        'files_count' => File::where('owner_id', $t->id)->count(),
                    ];
                });

            // Setup Data for View
            $data = [
                'generator_name' => $generatorName,
                'settings' => $activeSetting ? $activeSetting->toArray() : null,
                'users' => $users,
                'strands' => $strands,
                'academics' => $academics,
                'engagement' => $engagement,
                'teachers' => $teachers 
            ];

            if ($user) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'action' => 'Generated Report',
                    'description' => "Generated and downloaded the Comprehensive System Analytics Report."
                ]);
            }

            // Generate PDF
            $pdf = Pdf::loadView('print.report', $data);

            // Download directly to the browser
            return $pdf->download('HolyFace_System_Report_'.date('Y-m-d').'.pdf');

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to generate report: ' . $e->getMessage()], 500);
        }
    }

    // MAINTENANCE MODE TOGGLE
    public function toggleMaintenance(Request $request)
    {
        try {
            $activeSetting = SystemSetting::where('is_active', true)->first();
            
            if (!$activeSetting) {
                return response()->json(['message' => 'No active school setting found. Please set School Year first.'], 404);
            }

            // Toggle ang boolean value
            $activeSetting->maintenance_mode = !$activeSetting->maintenance_mode;
            
            // timestamp kung kailan nag-ON
            $activeSetting->maintenance_started_at = $activeSetting->maintenance_mode ? now() : null;
            $activeSetting->save();

            $status = $activeSetting->maintenance_mode ? 'enabled' : 'disabled';
            $statusText = $activeSetting->maintenance_mode ? 'ON' : 'OFF';

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Toggled Maintenance Mode',
                'description' => "Turned {$statusText} the system maintenance mode loop."
            ]);

            return response()->json([
                'message' => "Maintenance mode successfully $status.",
                'setting' => $activeSetting
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to toggle maintenance mode: ' . $e->getMessage()], 500);
        }
    }
}