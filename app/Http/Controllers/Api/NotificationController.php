<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    /**
     * Ambil notifikasi user (Optimized with Cache + Limit).
     * 
     * Query params:
     * - category: filter by category (system, payment, link, account, event)
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $category = $request->query('category');

        // Cache key based on user + category filter
        $cacheKey = "notifications:{$userId}:" . ($category ?? 'all');

        // Try to get from cache first (1 minute TTL)
        $notifications = Cache::remember($cacheKey, 60, function () use ($request, $category) {
            $query = $request->user()
                ->notifications()
                ->select(['id', 'type', 'data', 'read_at', 'created_at']) // Only needed columns
                ->where(function ($q) {
                    // Tampilkan jika expires_at NULL (permanen) ATAU expires_at > sekarang
                    $q->whereNull('data->expires_at')
                        ->orWhere('data->expires_at', '>', now());
                });

            // Filter by category if provided (not "all")
            if ($category && $category !== 'all') {
                $query->where('data->category', $category);
            }

            // Limit to 50 most recent notifications (no pagination needed for dropdown)
            return $query->latest()->limit(50)->get();
        });

        return $this->successResponse($notifications, 'Notifications retrieved');
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
