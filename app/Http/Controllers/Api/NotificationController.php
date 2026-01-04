<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlobalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    /**
     * Ambil notifikasi user (Optimized with Cache + Limit).
     * 
     * Query params:
     * - category: filter by category (system, payment, link, account, event)
     * 
     * Response includes:
     * - pinned: Global notifications (visible to all users)
     * - notifications: Personal notifications for this user
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $category = $request->query('category');

        // Helper function to map global notification to response format
        $mapGlobalNotif = function ($notif) {
            return [
                'id' => 'global_' . $notif->id,
                'type' => 'App\\Notifications\\GlobalBroadcast',
                'data' => [
                    'title' => $notif->title,
                    'message' => $notif->body,
                    'body' => $notif->body,
                    'type' => $notif->type,
                    'category' => 'system',
                    'is_global' => true,
                ],
                'read_at' => null,
                'created_at' => $notif->created_at,
            ];
        };

        // Get pinned global notifications (shown at top in special section)
        $pinnedNotifications = Cache::remember('global_notifications_pinned', 300, function () use ($mapGlobalNotif) {
            return GlobalNotification::where('is_pinned', true)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map($mapGlobalNotif);
        });

        // Get unpinned global notifications (shown in regular list)
        $unpinnedGlobalNotifications = Cache::remember('global_notifications_unpinned', 300, function () use ($mapGlobalNotif) {
            return GlobalNotification::where('is_pinned', false)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
                ->map($mapGlobalNotif);
        });

        // Cache key based on user + category filter
        $cacheKey = "notifications:{$userId}:" . ($category ?? 'all');

        // Get personal notifications
        $personalNotifications = Cache::remember($cacheKey, 60, function () use ($request, $category) {
            $query = $request->user()
                ->notifications()
                ->select(['id', 'type', 'data', 'read_at', 'created_at'])
                ->where(function ($q) {
                    $q->whereNull('data->expires_at')
                        ->orWhere('data->expires_at', '>', now());
                });

            if ($category && $category !== 'all') {
                $query->where('data->category', $category);
            }

            return $query->latest()->limit(50)->get();
        });

        // Merge unpinned global notifications with personal notifications
        // They'll appear in chronological order
        $mergedNotifications = collect($unpinnedGlobalNotifications)
            ->merge($personalNotifications)
            ->sortByDesc('created_at')
            ->values();

        return $this->successResponse([
            'pinned' => $pinnedNotifications,
            'notifications' => $mergedNotifications,
        ], 'Notifications retrieved');
    }

    /**
     * Hitung jumlah yang belum dibaca.
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();
        return $this->successResponse(['unread_count' => $count], 'Unread count retrieved');
    }

    /**
     * Tandai satu notifikasi sudah dibaca.
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if ($notification) {
            $notification->markAsRead();
            $this->clearNotificationCache($request->user()->id);
        }

        return $this->successResponse(null, 'Marked as read');
    }

    /**
     * Tandai SEMUA sudah dibaca.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        $this->clearNotificationCache($request->user()->id);
        return $this->successResponse(null, 'All marked as read');
    }

    /**
     * Hapus notifikasi.
     */
    public function destroy(Request $request, $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if ($notification) {
            $notification->delete();
            $this->clearNotificationCache($request->user()->id);
        }

        return $this->successResponse(null, 'Notification deleted');
    }

    /**
     * Clear notification cache for user.
     */
    private function clearNotificationCache($userId)
    {
        // Clear all category caches for this user
        $categories = ['all', 'system', 'payment', 'link', 'account', 'event'];
        foreach ($categories as $cat) {
            Cache::forget("notifications:{$userId}:{$cat}");
        }
    }
}
