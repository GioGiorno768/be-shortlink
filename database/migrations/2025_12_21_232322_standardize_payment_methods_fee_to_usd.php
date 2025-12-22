<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Exchange rate: 1 USD = 15800 IDR
    private const USD_TO_IDR_RATE = 15800;

    public function up(): void
    {
        // Convert payment_methods.fee from IDR to USD
        // Current values are in IDR (e.g., 6320, 6500)
        // Target values should be in USD (e.g., 0.40, 0.41)
        DB::table('payment_methods')
            ->where('fee', '>', 10) // If fee > 10, it's definitely in IDR
            ->update([
                'fee' => DB::raw('ROUND(fee / ' . self::USD_TO_IDR_RATE . ', 4)')
            ]);
    }

    public function down(): void
    {
        // Convert back from USD to IDR
        DB::table('payment_methods')
            ->where('fee', '<', 10) // If fee < 10, it's in USD
            ->update([
                'fee' => DB::raw('ROUND(fee * ' . self::USD_TO_IDR_RATE . ', 2)')
            ]);
    }
};
