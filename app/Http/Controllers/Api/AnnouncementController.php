<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Announcement;
use App\Models\File;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Support\PublicFileStorage;

class AnnouncementController extends Controller
{
    // SECURITY 
    private function checkAdmin(Request $request)
    {
        return $request->user() && $request->user()->role === 'admin';
    }

    // View announcement
    public function index(Request $request)
    {
        if (!$this->checkAdmin($request)) {
            return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);
        }

        try {
            $query = Announcement::with([
                'files',
                'creator',
                'comments' => function($q) {
                    $q->whereNull('parent_id')
                    ->with(['user', 'replies.user'])
                    ->orderBy('created_at', 'asc');
                }
            ])->where('creator_id', $request->user()->id);

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('content', 'LIKE', "%{$search}%");
                });
            }

            if ($request->has('filterAttachment') && $request->filterAttachment != 'all') {
                $attachment = $request->filterAttachment;
                if ($attachment === 'files') {
                    $query->has('files');
                } elseif ($attachment === 'links') {
                    $query->whereNotNull('link');
                } elseif ($attachment === 'both') {
                    $query->has('files')->whereNotNull('link');
                } elseif ($attachment === 'none') {
                    $query->doesntHave('files')->whereNull('link');
                }
            }

            if ($request->has('filterStatus') && $request->filterStatus != 'all') {
                $now = now();
                $status = $request->filterStatus;
                if ($status === 'Pending') {
                    $query->where('publish_from', '>', $now);
                } elseif ($status === 'Published') {
                    $query->where('publish_from', '<=', $now)
                        ->where('valid_until', '>=', $now);
                } elseif ($status === 'Done') {
                    $query->where('valid_until', '<', $now);
                }
            }

            $sortOrder = $request->input('sortDate', 'newest') === 'oldest' ? 'asc' : 'desc';
            $query->orderBy('created_at', $sortOrder);
            $entries = $request->has('entries') ? (int) $request->entries : 10;
            
            return response()->json($query->paginate($entries), 200);

        } catch (\Exception $e) {
            Log::error('AnnouncementController index Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while fetching announcements.'], 500);
        }
    }

    // Create Announcement
    public function store(Request $request)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        try {  
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'link' => 'nullable|url|starts_with:http://,https://',
                'publish_from' => 'required|date',
                'valid_until' => 'required|date|after:publish_from',
                'files' => 'nullable|array|max:5',
                'files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,gif,mp4,mov,avi|max:20480'
            ]);

            DB::beginTransaction();

            $announcement = Announcement::create([
                'creator_id' => $request->user()->id,
                'title' => $validated['title'],
                'content' => $validated['content'],
                'link' => $validated['link'] ?? null,
                'publish_from' => date('Y-m-d H:i:s', strtotime($validated['publish_from'])),
                'valid_until' => date('Y-m-d H:i:s', strtotime($validated['valid_until'])),
            ]);

            // storage path
            if ($request->hasFile('files')) {
                $uploadedFiles = PublicFileStorage::normalizeUploadedFiles($request->file('files'));

                foreach ($uploadedFiles as $file) {
                    validator(
                        ['upload' => $file],
                        ['upload' => 'required|file|mimes:pdf,jpg,jpeg,gif,mp4,mov,avi|max:20480']
                    )->validate();

                    $announcement->files()->create([
                        'owner_id' => $request->user()->id,
                        'name' => $file->getClientOriginalName(),
                        'path' => PublicFileStorage::storeUploaded($file, 'announcements'),
                        'file_extension' => $file->getClientOriginalExtension(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $publishFromDate = Carbon::parse($validated['publish_from']);
            $now = now();
            $adminName = $request->user()->first_name . ' ' . $request->user()->last_name;
            $announcementTitle = Str::limit($announcement->title, 25);

            if ($publishFromDate->greaterThan($now)) {
                $formattedDate = $publishFromDate->format('M d, Y h:i A'); // Apr 08, 2026 08:00 AM
                $descriptionText = "Admin {$adminName} scheduled an announcement: '{$announcementTitle}'. Wait for it on {$formattedDate}.";
            } else {
                $descriptionText = "Admin {$adminName} posted a new global announcement: '{$announcementTitle}'.";
            }

            $targetUsers = User::whereIn('role', ['student', 'teacher'])
                                ->where('status', 'active')
                                ->get();

            $notifications = [];
            $currentTime = $now->toDateTimeString();

            foreach ($targetUsers as $user) {
                $link = $user->role === 'student' ? "/student/home" : "/teacher/home";
                
                $notifications[] = [
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'description' => $descriptionText, 
                    'link' => $link,
                    'is_read' => false,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
            }

            if (!empty($notifications)) {
                foreach (array_chunk($notifications, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Created Announcement',
                'description' => "Posted a new global announcement: '{$announcementTitle}'."
            ]);
            
            DB::commit(); 
            return response()->json(['message' => 'Announcement posted successfully', 'announcement' => $announcement], 201);

        } catch (\Exception $e) {
            DB::rollBack(); 
            Log::error('AnnouncementController store Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while creating the announcement.'], 500);
        }
    }

    // Update Announcement
    public function update(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        try {
            $announcement = Announcement::where('creator_id', $request->user()->id)->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'link' => 'nullable|url|starts_with:http://,https://',
                'publish_from' => 'required|date',
                'valid_until' => 'required|date|after:publish_from',
                'files' => 'nullable|array|max:5',
                'files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,gif,mp4,mov,avi|max:20480'
            ]);

            DB::beginTransaction();

            $announcement->update([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'link' => empty($validated['link']) || $validated['link'] === 'null' ? null : $validated['link'],
                'publish_from' => date('Y-m-d H:i:s', strtotime($validated['publish_from'])),
                'valid_until' => date('Y-m-d H:i:s', strtotime($validated['valid_until'])),
            ]);

            if ($request->has('deleted_file_ids') && is_array($request->deleted_file_ids)) {
                $filesToDelete = File::whereIn('id', $request->deleted_file_ids)->get();
                foreach ($filesToDelete as $file) {
                    PublicFileStorage::deleteStored($file->getRawOriginal('path'));
                    $file->delete();
                }
            }

            // storage path
            if ($request->hasFile('files')) {
                $uploadedFiles = PublicFileStorage::normalizeUploadedFiles($request->file('files'));

                foreach ($uploadedFiles as $file) {
                    validator(
                        ['upload' => $file],
                        ['upload' => 'required|file|mimes:pdf,jpg,jpeg,gif,mp4,mov,avi|max:20480']
                    )->validate();

                    $announcement->files()->create([
                        'owner_id' => $request->user()->id,
                        'name' => $file->getClientOriginalName(),
                        'path' => PublicFileStorage::storeUploaded($file, 'announcements'),
                        'file_extension' => $file->getClientOriginalExtension(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $announcementTitle = Str::limit($announcement->title, 25);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Announcement',
                'description' => "Updated the global announcement: '{$announcementTitle}'."
            ]);

            DB::commit();
            return response()->json(['message' => 'Announcement updated successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AnnouncementController update Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while updating the announcement.'], 500);
        }
    }

    // Single Delete Announcement
    public function destroy(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        try {
            DB::beginTransaction();

            $announcement = Announcement::where('creator_id', $request->user()->id)->findOrFail($id);
            $announcementTitle = Str::limit($announcement->title, 25);

            // storage path
            foreach ($announcement->files as $file) {
                PublicFileStorage::deleteStored($file->getRawOriginal('path'));
            }

            $announcement->delete(); 

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Announcement',
                'description' => "Moved the global announcement '{$announcementTitle}' to the recycle bin."
            ]);

            DB::commit();
            return response()->json(['message' => 'Announcement moved to recycle bin.']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AnnouncementController destroy Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting the announcement.'], 500);
        }
    }

    // Bulk Delete Announcement
    public function bulkDelete(Request $request)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        $request->validate(['ids' => 'required|array']);

        try {
            DB::beginTransaction();
            $count = count($request->ids);

            Announcement::where('creator_id', $request->user()->id)
                ->whereIn('id', $request->ids)
                ->delete();

            if ($count > 0) {
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'Bulk Deleted Announcements',
                    'description' => "Moved {$count} selected announcements to the recycle bin."
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Selected announcements moved to recycle bin.']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AnnouncementController bulkDelete Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting announcements.'], 500);
        }
    }

    // Post Comment
    public function postComment(Request $request, $announcementId)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        $request->validate([
            'content' => 'required|string',
            'parent_id' => 'nullable'
        ]);

        try {
            $announcement = Announcement::findOrFail($announcementId);

            $comment = $announcement->comments()->create([
                'user_id' => $request->user()->id,
                'content' => $request->content,
                'parent_id' => $request->parent_id
            ]);

            $currentUser = $request->user();
            $adminName = $currentUser->first_name. ' ' . $currentUser->last_name;
            $snippet = Str::limit($request->content, 30);
            $announcementTitle = Str::limit($announcement->title, 25);

            if ($request->parent_id) {
                $parentComment = Comment::with('user')->find($request->parent_id);
                
                if ($parentComment && $parentComment->user_id !== $currentUser->id) {
                    $targetUser = $parentComment->user;
                    $role = $targetUser->role;
                    $link = "/";
                    if ($role === 'student') $link = "/student/home";
                    elseif ($role === 'teacher') $link = "/teacher/home";
                    elseif ($role === 'admin') $link = "/admin/announcements";
                    DB::table('notifications')->insert([
                        'id' => Str::uuid()->toString(),
                        'user_id' => $targetUser->id,
                        'description' => "Admin {$adminName} replied to your comment on '{$announcementTitle}': \"{$snippet}\"",
                        'link' => $link,
                        'is_read' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                $participantIds = $announcement->comments()
                    ->where('user_id', '!=', $currentUser->id)
                    ->pluck('user_id')
                    ->unique();

                $pUsers = User::whereIn('id', $participantIds)->get();
                $notifications = [];

                foreach ($pUsers as $pUser) {
                    $link = "/";
                    if ($pUser->role === 'student') $link = "/student/home";
                    elseif ($pUser->role === 'teacher') $link = "/teacher/home";
                    elseif ($pUser->role === 'admin') $link = "/admin/announcements";
                    
                    $notifications[] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $pUser->id,
                        'description' => "Admin {$adminName} added a comment on '{$announcementTitle}': \"{$snippet}\"",
                        'link' => $link,
                        'is_read' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($notifications)) {
                    foreach (array_chunk($notifications, 500) as $chunk) {
                        DB::table('notifications')->insert($chunk);
                    }
                }
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Commented on Announcement',
                'description' => "Added a comment/reply on the announcement '{$announcementTitle}'."
            ]);

            return response()->json(['message' => 'Comment posted successfully', 'comment' => $comment], 201);
        
        } catch (\Exception $e) {
            Log::error('AnnouncementController postComment Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while posting your comment.'], 500);
        }
    }

    // Update Comment
    public function updateComment(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        $request->validate(['content' => 'required|string']);

        try {
            $comment = Comment::where('user_id', $request->user()->id)->findOrFail($id);
            $comment->update(['content' => $request->content]);
            
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Updated Comment',
                'description' => "Updated a comment on an announcement thread."
            ]);

            return response()->json(['message' => 'Comment updated successfully']);

        } catch (\Exception $e) {
            Log::error('AnnouncementController updateComment Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while updating the comment.'], 500);
        }
    }

    // Delete Comment
    public function deleteComment(Request $request, $id)
    {
        if (!$this->checkAdmin($request)) return response()->json(['message' => 'Unauthorized access. Admin privileges required.'], 403);

        try {
            DB::beginTransaction();
            
            $comment = Comment::where('id', $id)
                ->where(function($query) use ($request) {
                    $query->where('user_id', $request->user()->id)
                        ->orWhere(DB::raw("'".$request->user()->role."'"), 'admin'); 
                })->firstOrFail();

            $comment->forceDelete(); 
            
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Deleted Comment',
                'description' => "Deleted a comment from an announcement thread."
            ]);
        
            DB::commit();
            return response()->json(['message' => 'Comment deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AnnouncementController deleteComment Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An error occurred while deleting the comment.'], 500);
        }
    }
}