<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create global_features table for managing ad level features
     */
    public function up(): void
    {
        Schema::create('global_features', function (Blueprint $table) {
            $table->id();
            $table->string('name');                      // Feature name (e.g., "Interstitial Ads")
            $table->text('description')->nullable();     // Optional description
            $table->integer('display_order')->default(0); // For sorting
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_features');
    }
};
