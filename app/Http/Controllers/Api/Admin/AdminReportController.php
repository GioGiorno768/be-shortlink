<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LinkReport;
use App\Models\Link;

class AdminReportController extends Controller
{
    /**
     * Get list of reports with filtering
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $status = $request->input('status', 'all');

        $query = LinkReport::with('link:id,code,original_url,is_banned,user_id')
            ->with('link.user:id,name,email');

        // Filter by status
        if ($status === 'pending') {
            $query->pending();
        } elseif ($status === 'resolved') {
            $query->resolved();
        }

        $reports = $query->latest()->paginate($perPage);

        return $this->paginatedResponse($reports, 'Reports retrieved');
    }

    /**
     * Get report statistics
     */
    public function stats()
    {
        $pendingCount = LinkReport::pending()->count();
        $resolvedToday = LinkReport::whereDate('resolved_at', today())
            ->whereIn('status', ['resolved', 'ignored'])
            ->count();
        $totalReports = LinkReport::count();

        return $this->successResponse([
            'pending_count' => $pendingCount,
            'resolved_today' => $resolvedToday,
            'total_reports' => $totalReports,
        ], 'Report stats retrieved');
    }

    /**
     * Resolve report (mark as resolved without blocking)
     */
    public function resolve($id)
    {
        $report = LinkReport::findOrFail($id);

        $report->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return $this->successResponse(null, 'Report marked as resolved.');
    }

    /**
     * Ignore report
     */
    public function ignore($id)
    {
        $report = LinkReport::findOrFail($id);

        $report->update([
            'status' => 'ignored',
            'resolved_at' => now(),
        ]);

        return $this->successResponse(null, 'Report ignored.');
    }

    /**
     * Block link and resolve report
     */
    public function blockLink($id)
    {
        $report = LinkReport::with('link')->findOrFail($id);

        // Block the link if it exists
        if ($report->link) {
            $report->link->update(['is_banned' => true]);
        } elseif ($report->link_id) {
            Link::where('id', $report->link_id)->update(['is_banned' => true]);
        }

        // Mark report as resolved
        $report->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return $this->successResponse(null, 'Link blocked and report resolved.');
    }

    /**
     * Delete a report
     */
    public function destroy($id)
    {
        $report = LinkReport::findOrFail($id);
        $report->delete();

        return $this->successResponse(null, 'Laporan berhasil dihapus.');
    }
}
