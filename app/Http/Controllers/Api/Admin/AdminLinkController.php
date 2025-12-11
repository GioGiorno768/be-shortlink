<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Notifications\GeneralNotification;
use App\Models\Setting;

class AdminLinkController extends Controller
{
    // 🔹 Ambil link user dengan pagination
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // default 10
        $search = $request->input('search');
        $isBanned = $request->input('is_banned'); // '1', '0', or null
        $sortBy = $request->input('sort_by', 'newest'); // newest, views, valid_views, earned

        $query = Link::with('user:id,name,email')
            ->withCount(['views as total_views'])
            ->withCount(['views as valid_views' => function ($q) {
                $q->where('is_valid', true);
            }])
            ->withSum('views as total_earned', 'earned');

        // 1. Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('original_url', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // 2. Filter Banned
        if ($isBanned !== null && $isBanned !== '') {
            $query->where('is_banned', $isBanned);
        }

        // 3. Sorting
        switch ($sortBy) {
            case 'views':
                $query->orderByDesc('total_views');
                break;
            case 'valid_views':
                $query->orderByDesc('valid_views');
                break;
            case 'earned':
                $query->orderByDesc('total_earned');
                break;
            case 'newest':
            default:
                $query->latest();
                break;
        }

        $links = $query->paginate($perPage);

        return $this->paginatedResponse($links, 'Links retrieved');
    }

    // 🔹 Update link
    public function update(Request $request, $id)
    {
        $request->validate([
            'is_banned' => 'boolean',
            'ban_reason' => 'nullable|string|max:255',
            'admin_comment' => 'nullable|string|max:1000',
        ]);

        $link = Link::findOrFail($id);

        $link->update([
            'is_banned' => $request->is_banned ?? $link->is_banned,
            'ban_reason' => $request->ban_reason ?? $link->ban_reason,
            'admin_comment' => $request->admin_comment ?? $link->admin_comment,
        ]);

        $link->load('user:id,name,email'); // pastikan relasi tetap ada

        // 🔥 Hapus cache agar perubahan status/ban langsung berasa
        Cache::forget("link:{$link->code}");

        // 🔔 Kirim notifikasi jika ada komentar admin
        if ($request->filled('admin_comment')) {
            $title = $link->is_banned ? 'Link Banned by Admin' : 'Admin Message regarding your link';
            $type = $link->is_banned ? 'danger' : 'info';
            
            // 🔥🔥 Fetch Expiry Setting 🔥🔥
            $setting = Setting::where('key', 'notification_settings')->first();
            $expiryDays = $setting ? ($setting->value['expiry_days'] ?? 30) : 30;
            $expiresAt = now()->addDays($expiryDays);

            $link->user->notify(new GeneralNotification(
                $title,
                $request->admin_comment,
                $type,
                null, // actionUrl bisa diisi link ke detail jika ada
                $expiresAt // ✅ Expiration Date
            ));
        }

        return $this->successResponse($link, 'Link updated successfully');
    }

    // 🔹 Hapus link
    public function destroy($id)
    {
        $link = Link::findOrFail($id);
        $link->delete();

        return $this->successResponse(null, 'Link deleted successfully');
    }

    // 🔹 Bulk Ban Link
    public function bulkBan(Request $request)
    {
        $request->validate([
            'keyword' => 'required|string|min:3',
            'field' => 'nullable|string|in:original_url,title,code',
            'ban_reason' => 'required|string|max:255',
            'admin_comment' => 'nullable|string|max:1000',
            'dry_run' => 'boolean'
        ]);

        $keyword = $request->keyword;
        $field = $request->field ?? 'original_url';
        $dryRun = $request->boolean('dry_run');

        // Query: Cari link yang mengandung keyword DAN belum di-ban
        $query = Link::where($field, 'LIKE', "%{$keyword}%")
                     ->where('is_banned', false);

        // Jika Dry Run (Preview)
        if ($dryRun) {
            $count = $query->count();
            return $this->successResponse([
                'dry_run' => true,
                'count' => $count,
                'message' => "Found {$count} active links matching '{$keyword}'."
            ], 'Dry run completed');
        }

        // Jika Eksekusi
        $links = $query->get(['id', 'code']); // Ambil ID dan Code untuk clear cache
        $count = $links->count();

        if ($count === 0) {
            return $this->errorResponse('No links found to ban.', 404);
        }

        // 1. Update Database Masal
        Link::whereIn('id', $links->pluck('id'))->update([
            'is_banned' => true,
            'ban_reason' => $request->ban_reason,
            'admin_comment' => $request->admin_comment,
            'updated_at' => now()
        ]);

        // 2. Clear Cache (Looping karena key cache spesifik per code)
        foreach ($links as $link) {
            Cache::forget("link:{$link->code}");
        }

        return $this->successResponse([
            'dry_run' => false,
            'count' => $count,
            'message' => "Successfully banned {$count} links."
        ], 'Bulk ban completed');
    }
}
