<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('views', function (Blueprint $table) {
            $table->string('visitor_id')->nullable()->index()->after('ip_address');
            $table->string('rejection_reason')->nullable()->after('note');
            $table->decimal('publisher_earning', 10, 4)->default(0)->after('earned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('views', function (Blueprint $table) {
            $table->dropColumn(['visitor_id', 'rejection_reason', 'publisher_earning']);
        });
    }
};
