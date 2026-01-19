<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ad_level_configs', function (Blueprint $table) {
            // Add feature_values JSON column for per-level feature descriptions
            // Example: {"1": "Max", "2": "On Page Load", "3": "3 / 24h"}
            $table->json('feature_values')->nullable()->after('enabled_features');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_level_configs', function (Blueprint $table) {
            $table->dropColumn('feature_values');
        });
    }
};
