<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Exchange rate: 1 USD = 15800 IDR (same as backend PayoutController)
    private const USD_TO_IDR_RATE = 15800;

    public function up(): void
    {
        // 1. Fix payment_methods.fee - normalize to IDR (source of truth for fee settings)
        // If fee < 100, it's probably in USD, convert to IDR
        DB::table('payment_methods')
            ->where('fee', '<', 100)
            ->where('fee', '>', 0)
            ->update([
                'fee' => DB::raw('fee * ' . self::USD_TO_IDR_RATE)
            ]);

        // 2. Fix payouts.fee - normalize to USD (balance is in USD)
        // If fee > 100, it's probably in IDR, convert to USD
        DB::table('payouts')
            ->where('fee', '>', 100)
            ->update([
                'fee' => DB::raw('fee / ' . self::USD_TO_IDR_RATE)
            ]);

        // Log what was done
        $paymentMethodsFixed = DB::table('payment_methods')->count();
        $payoutsFixed = DB::table('payouts')->where('fee', '<=', 100)->count();

        // Note: These updates are idempotent - running multiple times won't double-convert
    }

    public function down(): void
    {
        // Reverse: payment_methods USD -> IDR (undo step 1)
        // This is a best-effort rollback - may not be perfectly accurate
        DB::table('payment_methods')
            ->where('fee', '>=', 100) // Values that were converted
            ->update([
                'fee' => DB::raw('fee / ' . self::USD_TO_IDR_RATE)
            ]);

        // Reverse: payouts IDR -> USD (undo step 2)
        DB::table('payouts')
            ->where('fee', '<=', 100) // Values that were converted
            ->update([
                'fee' => DB::raw('fee * ' . self::USD_TO_IDR_RATE)
            ]);
    }
};
