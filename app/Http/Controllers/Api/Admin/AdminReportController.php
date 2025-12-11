<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LinkReport;

class AdminReportController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        
        $reports = LinkReport::with('link:id,code,original_url,is_banned') // Eager load link info
            ->latest()
            ->paginate($perPage);

        return $this->paginatedResponse($reports, 'Reports retrieved');
    }

    public function destroy($id)
    {
        $report = LinkReport::findOrFail($id);
        $report->delete();

        return $this->successResponse(null, 'Laporan berhasil dihapus.');
    }
}
