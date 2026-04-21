<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use \App\Models\ELibrary;
use App\Models\User;
use App\Models\ActivityLog;

class AdminELibraryController extends Controller
{
    // KUNIN LAHAT NG E-LIBRARIES (With Creator & Files)
    public function index(Request $request)
    {
        try {
            $eLibraries = ELibrary::with(['creator', 'files'])->orderBy('created_at', 'desc')->get();
            return response()->json($eLibraries, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // BULK APPROVE
    public function bulkApprove(Request $request)
    {
        try {
            $request->validate(['ids' => 'required|array']);
            
            ELibrary::whereIn('id', $request->ids)->update([
                'status' => 'approved',
                'admin_feedback' => null, // Linisin ang feedback kapag in-approve na
                'updated_at' => now()
            ]);

            // NOTIFICATION LOGIC
            $adminUser = $request->user();
            $adminName = $adminUser->first_name . ' ' . $adminUser->last_name;

            // Kunin ang E-Libraries KASAMA ang relationship ng creator (para makuha ang pangalan ng teacher)
            $libraries = ELibrary::with('creator')->whereIn('id', $request->ids)->get();
            
            // Kunin lahat ng ACTIVE students
            $students = User::where('role', 'student')
                                        ->where('status', 'active')
                                        ->get();

            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach($libraries as $lib) {
                // Pangalan ng gumawa ng module (Teacher)
                $creatorName = $lib->creator ? $lib->creator->first_name . ' ' . $lib->creator->last_name : 'a Teacher';
                
                // NOTIFICATION PARA KAY TEACHER (Creator)
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $lib->creator_id,
                    'description' => "Your E-Library material '{$lib->title}' was approved by Admin {$adminName}.",
                    'link' => "/teacher/e-library",
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];

                // NOTIFICATION PARA SA LAHAT NG STUDENTS
                foreach($students as $student) {
                    $notifications[] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $student->id,
                        'description' => "Admin {$adminName} added a new E-Library material: '{$lib->title}' by {$creatorName}.",
                        'link' => "/student/e-library", // Link papunta sa student e-library
                        'is_read' => false,
                        'created_at' => $currentTime,
                        'updated_at' => $currentTime,
                    ];
                }
            }

            // BULK INSERT: Hahatiin by 500s para mabilis
            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $count = count($request->ids);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Approved E-Library Materials',
                'description' => "Approved {$count} pending E-Library material(s)."
            ]);

            return response()->json(['message' => 'Selected materials approved successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // BULK DECLINE WITH FEEDBACK
    public function bulkDecline(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'feedback' => 'required|string'
            ]);
            
            ELibrary::whereIn('id', $request->ids)->update([
                'status' => 'declined',
                'admin_feedback' => $request->feedback,
                'updated_at' => now()
            ]);

            // NOTIFY TEACHERS (Decline)
            $libraries = ELibrary::whereIn('id', $request->ids)->get();
            foreach($libraries as $lib) {
                DB::table('notifications')->insert([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $lib->creator_id,
                    'description' => "Your E-Library material '{$lib->title}' was declined. Feedback: " . Str::limit($request->feedback, 30),
                    'link' => "/teacher/e-library",
                    'is_read' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $count = count($request->ids);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Declined E-Library Materials',
                'description' => "Declined {$count} pending E-Library material(s) and provided feedback."
            ]);

            return response()->json(['message' => 'Selected materials declined successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // BULK DELETE (SoftDeletes)
    public function bulkDelete(Request $request)
    {
        try {
            $request->validate(['ids' => 'required|array']);

            // KUNIN ANG E-LIBRARIES BAGO BURAHIN PARA SA NOTIF
            $libraries = ELibrary::whereIn('id', $request->ids)->get();

            // Soft Delete
            ELibrary::whereIn('id', $request->ids)->delete(); 

            // NOTIFICATION LOGIC 
            $admin = $request->user();
            $adminName = $admin ? $admin->first_name . ' ' . $admin->last_name : 'Admin';

            $notifications = [];
            $currentTime = now()->toDateTimeString();

            foreach ($libraries as $lib) {
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $lib->creator_id, // Kay Teacher na nag-create ise-send
                    'description' => "Admin {$adminName} deleted your E-Library material '{$lib->title}'. It was moved to the Recycle Bin.",
                    'link' => "/teacher/recycle-bin", // Link papunta sa recycle bin o e-library tab
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }

            // ISAHANG BULK INSERT
            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $count = count($request->ids);
            
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted E-Library Materials',
                'description' => "Moved {$count} E-Library material(s) to the recycle bin."
            ]);

            return response()->json(['message' => 'Selected materials moved to recycle bin.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}