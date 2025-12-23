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
    /**
     * 📊 Get link stats for dashboard cards
     */
    public function stats()
    {
        $today = now()->startOfDay();

        $totalLinks = Link::count();
        $linksToday = Link::where('created_at', '>=', $today)->count();
        $bannedLinks = Link::where('is_banned', true)->count();
        $activeLinks = Link::where('is_banned', false)->count();

        return $this->successResponse([
            'total_links' => $totalLinks,
            'links_today' => $linksToday,
            'banned_links' => $bannedLinks,
            'active_links' => $activeLinks,
        ], 'Link stats retrieved');
    }

    // 🔹 Ambil link user dengan pagination
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // default 10
        $search = $request->input('search');
        $isBanned = $request->input('is_banned'); // '1', '0', or null
        $sortBy = $request->input('sort_by', 'newest'); // newest, views, valid_views, earned
        $adLevel = $request->input('ad_level'); // filter by ad level


        // Use denormalized columns from links table directly
        // (views and valid_views columns are updated on each click)
        $query = Link::with('user:id,name,email,avatar')
            ->selectRaw('links.*, links.views as total_views, links.valid_views, links.total_earned');

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

        // 3. Filter by Ad Level
        if ($adLevel !== null && $adLevel !== '' && $adLevel !== 'all') {
            $query->where('ad_level', $adLevel);
        }

        // 4. Filter by Owner Type (guest/user)
        $ownerType = $request->input('owner_type');
        if ($ownerType === 'guest') {
            $query->whereNull('user_id');
        } elseif ($ownerType === 'user') {
            $query->whereNotNull('user_id');
        }

        // 5. Sorting
        switch ($sortBy) {
            case 'views':
            case 'most_views':
                $query->orderByDesc('total_views');
                break;
            case 'least_views':
                $query->orderBy('total_views', 'asc'); // Ascending for least
                break;
            case 'valid_views':
                $query->orderByDesc('valid_views');
                break;
            case 'earned':
            case 'most_earnings':
                $query->orderByDesc('total_earned');
                break;
            case 'least_earnings':
                $query->orderBy('total_earned', 'asc'); // Ascending for least
                break;
            case 'oldest':
                $query->oldest();
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

    /**
     * 🔹 Bulk Action by IDs (ban/activate)
     * 
     * Request params:
     * - action: 'ban' or 'activate'
     * - ids: array of link IDs (when selectAll is false)
     * - selectAll: boolean (if true, apply to all filtered links)
     * - filters: { is_banned, ad_level, search } (only used when selectAll is true)
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:ban,activate',
            'ids' => 'array',
            'ids.*' => 'integer',
            'selectAll' => 'boolean',
            'filters' => 'array',
            'filters.is_banned' => 'nullable|in:0,1',
            'filters.ad_level' => 'nullable|string',
            'filters.search' => 'nullable|string',
        ]);

        $action = $request->action;
        $selectAll = $request->boolean('selectAll');
        $isBan = $action === 'ban';

        if ($selectAll) {
            // Build query from filters
            $filters = $request->filters ?? [];
            $query = Link::query();

            if (isset($filters['is_banned']) && $filters['is_banned'] !== '') {
                $query->where('is_banned', $filters['is_banned']);
            }
            if (!empty($filters['ad_level']) && $filters['ad_level'] !== 'all') {
                $query->where('ad_level', $filters['ad_level']);
            }
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('original_url', 'like', "%{$search}%");
                });
            }

            $links = $query->get(['id', 'code']);
        } else {
            $ids = $request->ids ?? [];
            if (empty($ids)) {
                return $this->errorResponse('No links selected.', 400);
            }
            $links = Link::whereIn('id', $ids)->get(['id', 'code']);
        }

        $count = $links->count();
        if ($count === 0) {
            return $this->errorResponse('No links found to ' . $action . '.', 404);
        }

        // Update database
        Link::whereIn('id', $links->pluck('id'))->update([
            'is_banned' => $isBan,
            'updated_at' => now()
        ]);

        // Clear cache
        foreach ($links as $link) {
            Cache::forget("link:{$link->code}");
        }

        $actionText = $isBan ? 'banned' : 'activated';
        return $this->successResponse([
            'count' => $count,
            'action' => $action,
            'message' => "Successfully {$actionText} {$count} links."
        ], "Bulk {$action} completed");
    }
}
