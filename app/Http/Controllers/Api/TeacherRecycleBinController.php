<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Classroom;
use App\Models\Classwork;
use App\Models\Form;
use App\Models\File;
use App\Models\ELibrary;
use App\Models\AdvisoryClass;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class TeacherRecycleBinController extends Controller
{
    // RBAC
    private function checkTeacher(Request $request)
    {
        return $request->user() && $request->user()->role === 'teacher';
    }

    // view recycle
    public function index(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $trashedItems = collect();
            $userId = $request->user()->id;
            $user = $request->user();
            $userName = $user->first_name . ' ' . $user->last_name;
            $search = $request->input('search', '');
            $category = $request->input('category', 'all');
            $entries = (int) $request->input('entries', 10);
            $page = (int) $request->input('page', 1);

            // Classrooms
            if ($category === 'all' || $category === 'Classrooms') {
                $q = Classroom::onlyTrashed()->where('creator_id', $userId);
                if (!empty($search)) $q->where('section', 'like', "%{$search}%");
                
                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $trashedItems->push($this->formatItem($item->id, $item->section, 'Classrooms', $userName, $item->deleted_at));
                });
            }

            // Classworks
            if ($category === 'all' || $category === 'Classworks') {
                $q = Classwork::onlyTrashed()->whereHas('classroom', function($query) use ($userId) {
                    $query->withTrashed()->where('creator_id', $userId);
                });
                if (!empty($search)) {
                    $q->where(function($sub) use ($search) {
                        $sub->where('instruction', 'like', "%{$search}%")
                            ->orWhere('type', 'like', "%{$search}%");
                    });
                }

                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $title = ucfirst($item->type) . ' - ' . Str::limit($item->instruction, 30);
                    $trashedItems->push($this->formatItem($item->id, $title, 'Classworks', $userName, $item->deleted_at));
                });
            }

            // Forms
            if ($category === 'all' || $category === 'Forms') {
                $q = Form::onlyTrashed()->where('creator_id', $userId);
                if (!empty($search)) $q->where('name', 'like', "%{$search}%");

                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $trashedItems->push($this->formatItem($item->id, $item->name, 'Forms', $userName, $item->deleted_at));
                });
            }

            // Files
            if ($category === 'all' || $category === 'Files') {
                $q = File::onlyTrashed()->where('owner_id', $userId);
                if (!empty($search)) $q->where('name', 'like', "%{$search}%");

                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $trashedItems->push($this->formatItem($item->id, $item->name, 'Files', $userName, $item->deleted_at));
                });
            }

            // E-Libraries
            if ($category === 'all' || $category === 'E-Libraries') {
                $q = ELibrary::onlyTrashed()->where('creator_id', $userId);
                if (!empty($search)) $q->where('title', 'like', "%{$search}%");

                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $trashedItems->push($this->formatItem($item->id, $item->title, 'E-Libraries', $userName, $item->deleted_at));
                });
            }

            // Advisory Classes
            if ($category === 'all' || $category === 'Advisory Classes') {
                $q = AdvisoryClass::onlyTrashed()->where('teacher_id', $userId);
                if (!empty($search)) {
                    $q->where(function($sub) use ($search) {
                        $sub->where('section', 'like', "%{$search}%")
                            ->orWhere('school_year', 'like', "%{$search}%");
                    });
                }

                $q->get()->each(function ($item) use ($trashedItems, $userName) {
                    $trashedItems->push($this->formatItem($item->id, $item->section . ' (' . $item->school_year . ')', 'Advisory Classes', $userName, $item->deleted_at));
                });
            }

            // Pinakabagong nabura ang nasa itaas
            $sortedItems = $trashedItems->sortByDesc('deleted_at')->values();

            // Manual Pagination para sa pinagsama-samang collections
            $paginatedItems = new LengthAwarePaginator(
                $sortedItems->forPage($page, $entries)->values(),
                $sortedItems->count(),
                $entries,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json($paginatedItems, 200);

        } catch (\Throwable $e) {
            Log::error('Fetch Recycle Bin Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load recycle bin data.'], 500);
        }
    }

    // Display
    private function formatItem($id, $title, $type, $owner, $deletedAt)
    {
        return [
            'id' => $id,
            'title' => $title ?? 'Untitled Item',
            'type' => $type,
            'owner' => 'Me (' . $owner . ')',
            'deleted_at' => $deletedAt
        ];
    }

    // Categories
    private function getModelInstance($type)
    {
        return match ($type) {
            'Classrooms' => new Classroom(),
            'Classworks' => new Classwork(),
            'Forms' => new Form(),
            'Files' => new File(),
            'E-Libraries' => new ELibrary(),
            'Advisory Classes' => new AdvisoryClass(),
            default => null,
        };
    }

    // Double check kung si Teacher talaga ang may ari ng file bago i-restore
    private function verifyOwnership($record, $type, $userId)
    {
        if (!$record) return false;
        return match ($type) {
            'Classrooms', 'Forms', 'E-Libraries' => $record->creator_id === $userId,
            'Files' => $record->owner_id === $userId,
            'Advisory Classes' => $record->teacher_id === $userId,
            'Classworks' => $record->classroom()->withTrashed()->first()?->creator_id === $userId,
            default => false,
        };
    }

    // RESTORE 
    public function restore(Request $request)
    {
        if (!$this->checkTeacher($request)) {
            return response()->json(['message' => 'Unauthorized Access. Teachers only.'], 403);
        }

        try {
            $request->validate([
                'items' => 'required|array|max:50',
                'items.*.id' => 'required',
                'items.*.type' => 'required|string'
            ]);

            $userId = $request->user()->id;
            $items = $request->input('items'); 
            $restoredCount = 0;
            
            foreach ($items as $item) {
                $model = $this->getModelInstance($item['type']);
                if ($model) {
                    $record = $model->withTrashed()->find($item['id']);
                    // Titingnan kung sa kanya bago i-restore
                    if ($this->verifyOwnership($record, $item['type'], $userId)) {
                        $record->restore();
                        $restoredCount++;
                    }
                }
            }

            // ACTIVITY LOG
            if ($restoredCount > 0) {
                ActivityLog::create([
                    'user_id' => $userId,
                    'action' => 'Restored Items',
                    'description' => "Restored {$restoredCount} item(s) from the recycle bin back to their original locations."
                ]);
            }
            
            return response()->json(['message' => 'Items successfully restored.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Restore Recycle Bin Error: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while restoring items.'], 500);
        }
    }
}