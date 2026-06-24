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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; 
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    // security
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // View School Setting
    public function index(Request $request)
    {
        try {
            $activeSetting = Cache::remember('active_system_setting', 60, function () {
                return SystemSetting::where('is_active', true)->first();
            });

            return response()->json($activeSetting, 200);

        } catch (\Exception $e) {
            Log::error('SystemSettingController index Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching settings.'], 500);
        }
    }

    // Set School Setting
    public function store(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $validated = $request->validate([
                'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
                'term' => ['required', 'in:1st,2nd,3rd']
            ], [
                'term.in' => 'Invalid term selected. It must be 1st, 2nd, or 3rd.'
            ]);

            SystemSetting::where('is_active', true)->update(['is_active' => false]);

            $newSetting = SystemSetting::create([
                'school_year' => $validated['school_year'],
                'term' => $validated['term'],
                'maintenance_mode' => false,
                'is_active' => true
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated System Settings',
                'description' => "Set the active School Year to {$newSetting->school_year} and Term to {$newSetting->term} Term."
            ]);

            Cache::forget('active_system_setting'); 

            return response()->json([
                    'message' => 'School Settings successfully updated!',
                    'setting' => $newSetting
                ], 200);

        } catch (\Exception $e) {
            Log::error('SystemSettingController store Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while saving the settings.'], 500);
        }
    }

    // Reset School Setting
    public function reset(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $activeSetting = SystemSetting::where('is_active', true)->first();
            
            if ($activeSetting) {
                $activeSetting->delete();

                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Reset System Settings',
                    'description' => "Cleared the active School Year and Term configurations."
                ]);
            }

            Cache::forget('active_system_setting'); 

            return response()->json(['message' => 'School settings have been completely reset.'], 200);

        } catch (\Exception $e) {
            Log::error('SystemSettingController reset Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while resetting settings.'], 500);
        }
    }

    // pdf report generator
    public function generateReport(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $user = Auth::user();
            $generatorName = $user ? $user->first_name . ' ' . $user->last_name : 'Administrator';

            $activeSetting = Cache::remember('active_system_setting', 60, function () {
                return SystemSetting::where('is_active', true)->first();
            });

            $users = [
                'students_active' => User::where('role', 'student')->where('status', 'active')->count(),
                'students_inactive' => User::where('role', 'student')->where('status', 'inactive')->count(),
                'teachers_active' => User::where('role', 'teacher')->where('status', 'active')->count(),
                'teachers_inactive' => User::where('role', 'teacher')->where('status', 'inactive')->count(),
                'admins_active' => User::where('role', 'admin')->where('status', 'active')->count(),
                'admins_inactive' => User::where('role', 'admin')->where('status', 'inactive')->count(),
            ];

            $strands = Strand::withCount(['users' => function($query) {
                $query->where('role', 'student');
            }])->get();

            $academics = [
                'subjects' => Subject::count(),
                'classrooms' => Classroom::count(),
                'advisories' => AdvisoryClass::count(),
            ];

            $engagement = [
                'classworks' => Classwork::count(),
                'forms' => Form::count(),
                'announcements' => Announcement::count(),
                'files' => File::count(),
                'elibrary' => ELibrary::where('status', 'approved')->count(),
            ];

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

            $pdf = Pdf::loadView('print.report', $data);

            return $pdf->download('HolyFace_System_Report_'.date('Y-m-d').'.pdf');

        } catch (\Exception $e) {
            Log::error('SystemSettingController generateReport Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while generating the report.'], 500);
        }
    }

    // maintenance mode
    public function toggleMaintenance(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            DB::beginTransaction();
            $activeSetting = SystemSetting::where('is_active', true)->lockForUpdate()->first();
            
            if (!$activeSetting) {
                DB::rollBack();
                return response()->json(['message' => 'No active school setting found. Please set School Year first.'], 404);
            }

            $activeSetting->maintenance_mode = !$activeSetting->maintenance_mode;
            $activeSetting->maintenance_started_at = $activeSetting->maintenance_mode ? now() : null;
            $activeSetting->save();
            $status = $activeSetting->maintenance_mode ? 'enabled' : 'disabled';
            $statusText = $activeSetting->maintenance_mode ? 'ON' : 'OFF';

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Toggled Maintenance Mode',
                'description' => "Turned {$statusText} the system maintenance mode loop."
            ]);

            Cache::forget('active_system_setting'); 

            DB::commit(); 
            return response()->json([
                'message' => "Maintenance mode successfully $status.",
                'setting' => $activeSetting
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('SystemSettingController toggleMaintenance Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while toggling maintenance mode.'], 500);
        }
    }
}