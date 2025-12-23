<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add currency fields to payouts table to store user's local currency info
     * So admin knows exactly how much to transfer in user's currency
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            // User's currency code (e.g., 'IDR', 'USD', 'EUR')
            $table->string('currency', 10)->default('USD')->after('fee');

            // Amount in user's local currency (e.g., 32000 for IDR)
            $table->decimal('local_amount', 15, 2)->nullable()->after('currency');

            // Exchange rate at time of withdrawal (e.g., 15800 for USD to IDR)
            $table->decimal('exchange_rate', 15, 6)->default(1)->after('local_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['currency', 'local_amount', 'exchange_rate']);
        });
    }
};
