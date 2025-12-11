<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Ambil semua notifikasi user (Pagination).
     */
    public function index(Request $request)
    {
        // Laravel otomatis menyediakan method notifications() pada model User
        // yang menggunakan trait Notifiable
        $notifications = $request->user()
            ->notifications()
            ->where(function ($query) {
                // Tampilkan jika expires_at NULL (permanen) ATAU expires_at > sekarang
                $query->whereNull('data->expires_at')
                      ->orWhere('data->expires_at', '>', now());
            })
            ->latest()
            ->paginate(10);

        return $this->paginatedResponse($notifications, 'Notifications retrieved');
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
        }

        return $this->successResponse(null, 'Marked as read');
    }

    /**
     * Tandai SEMUA sudah dibaca.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
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
        }

        return $this->successResponse(null, 'Notification deleted');
    }
}