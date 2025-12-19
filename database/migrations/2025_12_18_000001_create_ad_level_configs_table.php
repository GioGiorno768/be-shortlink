<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_level_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // "Low", "Medium", "High", "Aggressive"
            $table->string('slug')->unique();          // "low", "medium", "high", "aggressive"
            $table->text('description')->nullable();   // UI description
            $table->string('demo_url')->nullable();    // Demo link
            $table->string('color_theme')->default('blue'); // green, blue, orange, red
            $table->integer('revenue_share')->default(50);  // Percentage shown in UI
            $table->boolean('is_popular')->default(false);  // Show "MOST POPULAR" badge
            $table->json('features')->nullable();      // [{label, value, included}]
            $table->integer('display_order')->default(0);   // Sort order
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_level_configs');
    }
};
