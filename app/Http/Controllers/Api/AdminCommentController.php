<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class AdminCommentController extends Controller
{
    // Delete ng specific comment or reply
    public function destroy(Request $request, $id)
    {
        $comment = Comment::findOrFail($id);

        // Kung parent comment ito, idelete din natin ang lahat ng replies na nakakabit sa kanya
        Comment::where('parent_id', $comment->id)->delete();

        // Idelete ang mismong comment
        $comment->delete();

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Deleted Comment',
            'description' => "Deleted a comment/reply from a classwork stream."
        ]);
        
        return response()->json(['message' => 'Comment deleted successfully.'], 200);
    }
}