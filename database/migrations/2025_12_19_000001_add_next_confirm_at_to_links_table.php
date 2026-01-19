<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add next_confirm_at column for guest link free pass feature.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->unsignedBigInteger('next_confirm_at')->nullable()->default(2)->after('views');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('next_confirm_at');
        });
    }
};
