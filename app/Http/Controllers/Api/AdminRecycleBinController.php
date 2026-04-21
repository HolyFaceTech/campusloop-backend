<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\Form;
use App\Models\File;
use App\Models\Announcement;
use App\Models\ELibrary;
use App\Models\Strand;
use App\Models\Subject;
use App\Models\AdvisoryClass;
use App\Models\ActivityLog;
use Illuminate\Support\Str;

class AdminRecycleBinController extends Controller
{
    public function index()
    {
        try {
            $trashedItems = collect();

            // Users
            User::onlyTrashed()->get()->each(function ($item) use ($trashedItems) {
                $trashedItems->push($this->formatItem($item->id, $item->first_name . ' ' . $item->last_name, 'Users', 'System', $item->deleted_at));
            });

            // Classrooms
            Classroom::onlyTrashed()->with('creator')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->creator ? $item->creator->first_name . ' ' . $item->creator->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->section, 'Classrooms', $owner, $item->deleted_at));
            });

            // Classworks
            Classwork::onlyTrashed()->with('classroom.creator')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->classroom && $item->classroom->creator ? $item->classroom->creator->first_name . ' ' . $item->classroom->creator->last_name : 'System';
                $title = ucfirst($item->type) . ' - ' . Str::limit($item->instruction, 30);
                $trashedItems->push($this->formatItem($item->id, $title, 'Classworks', $owner, $item->deleted_at));
            });

            // Forms
            Form::onlyTrashed()->with('creator')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->creator ? $item->creator->first_name . ' ' . $item->creator->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->name, 'Forms', $owner, $item->deleted_at));
            });

            // Files
            File::onlyTrashed()->with('owner')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->owner ? $item->owner->first_name . ' ' . $item->owner->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->name, 'Files', $owner, $item->deleted_at));
            });

            // Announcements
            Announcement::onlyTrashed()->with('creator')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->creator ? $item->creator->first_name . ' ' . $item->creator->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->title, 'Announcements', $owner, $item->deleted_at));
            });

            // E-Libraries
            ELibrary::onlyTrashed()->with('creator')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->creator ? $item->creator->first_name . ' ' . $item->creator->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->title, 'E-Libraries', $owner, $item->deleted_at));
            });

            // Strands
            Strand::onlyTrashed()->get()->each(function ($item) use ($trashedItems) {
                $trashedItems->push($this->formatItem($item->id, $item->name, 'Strands', 'System', $item->deleted_at));
            });

            // Subjects
            Subject::onlyTrashed()->get()->each(function ($item) use ($trashedItems) {
                $trashedItems->push($this->formatItem($item->id, $item->code . ' - ' . $item->description, 'Subjects', 'System', $item->deleted_at));
            });

            // Advisory Classes
            AdvisoryClass::onlyTrashed()->with('teacher')->get()->each(function ($item) use ($trashedItems) {
                $owner = $item->teacher ? $item->teacher->first_name . ' ' . $item->teacher->last_name : 'System';
                $trashedItems->push($this->formatItem($item->id, $item->section . ' (' . $item->school_year . ')', 'Advisory Classes', $owner, $item->deleted_at));
            });

            // Sort: Pinakabagong nabura ang nasa itaas
            $sortedItems = $trashedItems->sortByDesc('deleted_at')->values()->all();

            return response()->json($sortedItems, 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to load recycle bin data.', 'error' => $e->getMessage()], 500);
        }
    }

    // Display
    private function formatItem($id, $title, $type, $owner, $deletedAt)
    {
        return [
            'id' => $id,
            'title' => $title ?? 'Untitled Item',
            'type' => $type,
            'owner' => $owner,
            'deleted_at' => $deletedAt
        ];
    }

    // Categories
    private function getModelInstance($type)
    {
        return match ($type) {
            'Users' => new User(),
            'Classrooms' => new Classroom(),
            'Classworks' => new Classwork(),
            'Forms' => new Form(),
            'Files' => new File(),
            'Announcements' => new Announcement(),
            'E-Libraries' => new ELibrary(),
            'Strands' => new Strand(),
            'Subjects' => new Subject(),
            'Advisory Classes' => new AdvisoryClass(),
            default => null,
        };
    }

    // Isahang logic para sa Restore
    public function restore(Request $request)
    {
        $items = $request->input('items', []); 
        $restoredCount = 0;

        foreach ($items as $item) {
            $model = $this->getModelInstance($item['type']);
            if ($model) {
                $record = $model->withTrashed()->find($item['id']);
                if ($record) {
                    $record->restore();
                    $restoredCount++;
                }
            }
        }

        if ($restoredCount > 0) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Restored Items',
                'description' => "Restored {$restoredCount} item(s) from the recycle bin back to their original locations."
            ]);
        }

        return response()->json(['message' => 'Items successfully restored to their original locations.']);
    }

    // Isahang logic para sa Permanent Delete
    public function forceDelete(Request $request)
    {
        $items = $request->input('items', []);
        $deletedCount = 0;

        foreach ($items as $item) {
            $model = $this->getModelInstance($item['type']);
            if ($model) {
                $record = $model->withTrashed()->find($item['id']);
                if ($record) {
                    $record->forceDelete(); // Binubura na talaga sa database
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount > 0) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Permanently Deleted Items',
                'description' => "Permanently deleted {$deletedCount} item(s) from the recycle bin."
            ]);
        }

        return response()->json(['message' => 'Items permanently deleted from the database.']);
    }
}