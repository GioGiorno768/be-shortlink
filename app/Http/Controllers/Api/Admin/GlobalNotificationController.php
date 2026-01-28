<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GlobalNotificationController extends Controller
{
    /**
     * Get all global notifications (for admin management).
     */
    public function index()
    {
        $notifications = GlobalNotification::orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifications,
        ]);
    }

    /**
     * Create a new global notification.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'type' => 'required|in:info,warning,danger',
            'body' => 'required|string|max:500',
        ]);

        $notification = GlobalNotification::create([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'body' => $validated['body'],
            'created_by' => auth()->id(),
        ]);

        // Clear global notifications cache so all users see the new notification
        Cache::forget('global_notifications_pinned');
        Cache::forget('global_notifications_unpinned');

        return response()->json([
            'status' => 'success',
            'message' => 'Global notification created successfully.',
            'data' => $notification,
        ], 201);
    }

    /**
     * Delete a global notification.
     */
    public function destroy($id)
    {
        $notification = GlobalNotification::findOrFail($id);
        $notification->delete();

        // Clear global notifications cache
        Cache::forget('global_notifications_pinned');
        Cache::forget('global_notifications_unpinned');

        return response()->json([
            'status' => 'success',
            'message' => 'Global notification deleted successfully.',
        ]);
    }

    /**
     * Toggle pin status of a global notification.
     */
    public function togglePin($id)
    {
        $notification = GlobalNotification::findOrFail($id);
        $notification->is_pinned = !$notification->is_pinned;
        $notification->save();

        // Clear global notifications cache
        Cache::forget('global_notifications_pinned');
        Cache::forget('global_notifications_unpinned');

        return response()->json([
            'status' => 'success',
            'message' => $notification->is_pinned
                ? 'Notification pinned successfully.'
                : 'Notification unpinned successfully.',
            'data' => $notification,
        ]);
    }
}
