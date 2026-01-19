<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add indexes to optimize analytics queries.
     */
    public function up(): void
    {
        // Add index on links.user_id for faster owner lookups
        Schema::table('links', function (Blueprint $table) {
            $table->index('user_id', 'links_user_id_index');
        });

        // Add composite index on views for analytics queries
        // This helps with queries that filter by is_valid AND created_at
        Schema::table('views', function (Blueprint $table) {
            $table->index(['is_valid', 'created_at'], 'views_is_valid_created_at_index');
            $table->index(['link_id', 'is_valid', 'created_at'], 'views_link_id_valid_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex('links_user_id_index');
        });

        Schema::table('views', function (Blueprint $table) {
            $table->dropIndex('views_is_valid_created_at_index');
            $table->dropIndex('views_link_id_valid_created_index');
        });
    }
};
