<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Aggregate table for tracking referrer sources per user.
     * Only stores COUNT per referrer, not individual visitor records.
     */
    public function up(): void
    {
        Schema::create('user_referrer_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('referrer_key', 50); // e.g., "youtube", "facebook", "direct", "other"
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamps();

            // Unique constraint: one row per user+referrer
            $table->unique(['user_id', 'referrer_key']);

            // Index for fast lookups
            $table->index(['user_id', 'view_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_referrer_stats');
    }
};
