<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update all currency columns from 2 decimals to 5 decimals for micro-transaction precision
     */
    public function up(): void
    {
        // Users table
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 15, 5)->default(0)->change();
            $table->decimal('pending_balance', 15, 5)->default(0)->change();
            $table->decimal('total_earnings', 15, 5)->default(0)->change();
        });

        // Links table
        Schema::table('links', function (Blueprint $table) {
            $table->decimal('total_earned', 15, 5)->default(0)->change();
            // earn_per_click already (10,5) - no change needed
        });

        // Payouts table
        Schema::table('payouts', function (Blueprint $table) {
            $table->decimal('amount', 15, 5)->change();
            $table->decimal('fee', 15, 5)->default(0)->change();
        });

        // Transactions table
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('amount', 16, 5)->change();
        });

        // Payment methods table
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee', 15, 5)->default(0)->change();
        });

        // Levels table
        Schema::table('levels', function (Blueprint $table) {
            $table->decimal('min_total_earnings', 15, 5)->default(0)->change();
            $table->decimal('bonus_percentage', 5, 2)->default(0)->change(); // keep 2 for percentage
            $table->decimal('monthly_withdrawal_limit', 15, 5)->default(100.00)->change();
        });

        // User daily stats table
        Schema::table('user_daily_stats', function (Blueprint $table) {
            $table->decimal('earnings', 15, 5)->default(0)->change();
        });

        // Views table - publisher_earning
        Schema::table('views', function (Blueprint $table) {
            $table->decimal('publisher_earning', 15, 5)->default(0)->change();
            // earned already (10,5) - no change needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Users table
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 10, 2)->default(0)->change();
            $table->decimal('pending_balance', 10, 2)->default(0)->change();
            $table->decimal('total_earnings', 10, 4)->default(0)->change();
        });

        // Links table
        Schema::table('links', function (Blueprint $table) {
            $table->decimal('total_earned', 10, 4)->default(0)->change();
        });

        // Payouts table
        Schema::table('payouts', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->change();
            $table->decimal('fee', 10, 2)->default(0)->change();
        });

        // Transactions table
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('amount', 16, 2)->change();
        });

        // Payment methods table
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->default(0)->change();
        });

        // Levels table
        Schema::table('levels', function (Blueprint $table) {
            $table->decimal('min_total_earnings', 15, 2)->default(0)->change();
            $table->decimal('monthly_withdrawal_limit', 15, 2)->default(100.00)->change();
        });

        // User daily stats table
        Schema::table('user_daily_stats', function (Blueprint $table) {
            $table->decimal('earnings', 12, 4)->default(0)->change();
        });

        // Views table
        Schema::table('views', function (Blueprint $table) {
            $table->decimal('publisher_earning', 10, 4)->default(0)->change();
        });
    }
};
