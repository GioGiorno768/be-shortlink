<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Link;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    // Statistik global
    public function overview()
    {
        $today = now()->startOfDay();

        // 1. Basic Stats
        $totalUsers = User::count();
        $totalLinks = Link::count();
        $totalClicks = DB::table('views')->count(); 
        
        // 2. Withdrawal Stats (General)
        $pendingWithdrawalsCount = Payout::where('status', 'pending')->count();
        $totalWithdrawalsAmount = Payout::where('status', 'approved')->sum('amount');
        $pendingPaymentsTotal = Payout::where('status', 'pending')->sum('amount');

        // 3. Today's Stats
        $paymentsToday = Payout::where('status', 'approved')
            ->where('updated_at', '>=', $today)
            ->get();
        
        $paymentsTodayAmount = $paymentsToday->sum('amount');
        $paymentsTodayCount = $paymentsToday->count();
        $usersPaidToday = $paymentsToday->pluck('user_id')->unique()->count();
        
        $linksCreatedToday = Link::where('created_at', '>=', $today)->count();

        // 4. New Users Last 5 Days
        $newUsersLast5Days = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(5))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        // 5. Pending Withdrawals List (Limit 10)
        $pendingWithdrawalsList = Payout::with('user:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc') // Prioritize oldest
            ->limit(10)
            ->get()
            ->map(function ($payout) {
                return [
                    'id' => $payout->id,
                    'username' => $payout->user ? $payout->user->name : 'Unknown',
                    'amount' => $payout->amount,
                    'method' => $payout->method,
                    'created_at' => $payout->created_at->format('Y-m-d H:i'),
                ];
            });

        return $this->successResponse([
            'total_users' => $totalUsers,
            'active_users' => 0, // Placeholder
            'total_links' => $totalLinks,
            'total_clicks' => $totalClicks,
            'pending_withdrawals' => $pendingWithdrawalsCount,
            'total_withdrawals_amount' => $totalWithdrawalsAmount,
            
            // New Data
            'payments_today_amount' => $paymentsTodayAmount,
            'payments_today_count' => $paymentsTodayCount,
            'users_paid_today' => $usersPaidToday,
            'links_created_today' => $linksCreatedToday,
            'pending_payments_total' => $pendingPaymentsTotal,
            'new_users_last_5_days' => $newUsersLast5Days,
            'pending_withdrawals_list' => $pendingWithdrawalsList,
        ], 'Admin dashboard overview retrieved');
    }

    // Grafik tren user & transaksi
    public function trends()
    {
        $userGrowth = User::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $transactionVolume = Payout::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(amount) as amount')
            ->where('status', 'approved') // Only count approved payouts
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->successResponse([
            'user_growth' => $userGrowth,
            'transaction_volume' => $transactionVolume,
        ], 'Trends data retrieved');
    }
}
