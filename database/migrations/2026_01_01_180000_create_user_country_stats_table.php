<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Aggregate table for tracking visitor countries per user.
     * Only stores COUNT per country, not individual visitor records.
     */
    public function up(): void
    {
        Schema::create('user_country_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->char('country_code', 5); // ISO country code (ID, US, etc.) or "OTHER"
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamps();

            // Unique constraint: one row per user+country
            $table->unique(['user_id', 'country_code']);

            // Index for fast lookups
            $table->index(['user_id', 'view_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_country_stats');
    }
};
