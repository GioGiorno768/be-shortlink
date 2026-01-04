<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add is_enabled and enabled_features fields to ad_level_configs table
     */
    public function up(): void
    {
        Schema::table('ad_level_configs', function (Blueprint $table) {
            // Add is_enabled field - allows disabling levels without deleting
            $table->boolean('is_enabled')->default(true)->after('is_popular');

            // Add enabled_features field - stores array of global feature IDs
            $table->json('enabled_features')->nullable()->after('features');

            // Add is_default field - marks level as default for new links
            $table->boolean('is_default')->default(false)->after('is_enabled');

            // Add is_recommended field - shows "RECOMMENDED" badge
            $table->boolean('is_recommended')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('ad_level_configs', function (Blueprint $table) {
            $table->dropColumn(['is_enabled', 'enabled_features', 'is_default', 'is_recommended']);
        });
    }
};
