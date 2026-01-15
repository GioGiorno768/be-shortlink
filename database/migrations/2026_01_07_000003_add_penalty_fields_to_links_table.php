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
        Schema::table('links', function (Blueprint $table) {
            $table->decimal('cpc_penalty_percent', 5, 2)->default(0)->after('status');
            $table->timestamp('penalty_applied_at')->nullable()->after('cpc_penalty_percent');
            $table->timestamp('penalty_expires_at')->nullable()->after('penalty_applied_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['cpc_penalty_percent', 'penalty_applied_at', 'penalty_expires_at']);
        });
    }
};
