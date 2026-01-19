<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Aggregate table for daily stats per user.
     * Enables date filtering (week/month/year) without storing raw visitor records.
     * Only 1 row per user per day (max 365 rows per user per year).
     */
    public function up(): void
    {
        Schema::create('user_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date'); // The day these stats are for
            $table->unsignedBigInteger('views')->default(0); // Total views (all clicks)
            $table->unsignedBigInteger('valid_views')->default(0); // Valid views (non-fraud)
            $table->decimal('earnings', 12, 4)->default(0); // Total earnings for the day
            $table->timestamps();

            // Unique constraint: one row per user+date
            $table->unique(['user_id', 'date']);

            // Index for date range queries
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_daily_stats');
    }
};
