<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminClassroomController extends Controller
{
    // Fetch all classrooms with relations and enrolled count
    public function index()
    {
        $classrooms = Classroom::with(['creator', 'subject', 'strand'])
            ->withCount(['students as enrolled_count' => function ($query) {
                $query->where('classroom_student.status', 'approved');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($classrooms, 200);
    }

    // Bulk Delete (Soft Delete)
    public function destroyBulk(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:classrooms,id'
        ]);

        // KUNIN ANG CLASSROOMS BAGO BURAHIN PARA SA NOTIF
        $classrooms = Classroom::with('subject')->whereIn('id', $request->ids)->get();

        // Soft Delete
        Classroom::whereIn('id', $request->ids)->delete();

        // NOTIFICATION LOGIC (DELETED CLASSROOM)
        $admin = $request->user();
        $adminName = $admin ? $admin->first_name . ' ' . $admin->last_name : 'Admin';

        $notifications = [];
        $currentTime = now()->toDateTimeString();

        foreach ($classrooms as $classroom) {
            $teacherId = $classroom->creator_id;
            $subjectName = $classroom->subject ? $classroom->subject->description : 'the class';
            $sectionName = $classroom->section;

            if ($teacherId) {
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $teacherId,
                    'description' => "Admin {$adminName} deleted your classroom {$subjectName} ({$sectionName}). It was moved to the Recycle Bin.",
                    'link' => "/teacher/recycle-bin", // Link papunta sa recycle bin para makita
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }
        }

        // Bulk insert
        if (!empty($notifications)) {
            foreach (array_chunk($notifications, 500) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }
        }

        $count = count($request->ids);

        if ($count > 0) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Classrooms',
                'description' => "Moved {$count} selected classroom(s) to the recycle bin."
            ]);
        }

        return response()->json(['message' => 'Selected classrooms moved to recycle bin.'], 200);
    }

    // Fetch Specific Classroom for Inside View
    public function show($id)
    {
        $classroom = Classroom::with(['creator', 'subject', 'strand'])
            ->withCount(['students as enrolled_count' => function ($query) {
                $query->where('classroom_student.status', 'approved');
            }])
            ->findOrFail($id);

        return response()->json($classroom, 200);
    }
}