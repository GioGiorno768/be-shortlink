<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Performance Optimization: Add indexes for frequently queried columns
 * 
 * These indexes speed up common queries without changing any functionality.
 * All indexes are additive and can be safely rolled back.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip for SQLite (testing) - indexes already created by primary table migrations
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Index for referral queries: User::where('referred_by', $user->id)->count()
        Schema::table('users', function (Blueprint $table) {
            $table->index('referred_by', 'users_referred_by_index');
            $table->index('referral_code', 'users_referral_code_index');
        });

        // Composite index for payout queries with status filter
        Schema::table('payouts', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'payouts_user_id_status_index');
        });

        // Composite index for links with status filter
        Schema::table('links', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'links_user_id_status_index');
            // Index for top links sorting
            $table->index(['user_id', 'total_earned'], 'links_user_id_total_earned_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip for SQLite
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_referred_by_index');
            $table->dropIndex('users_referral_code_index');
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex('payouts_user_id_status_index');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex('links_user_id_status_index');
            $table->dropIndex('links_user_id_total_earned_index');
        });
    }
};
