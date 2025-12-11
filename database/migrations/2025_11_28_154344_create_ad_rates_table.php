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
        Schema::create('ad_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country')->unique(); // 'ID', 'US', 'GLOBAL'
            $table->decimal('level_1', 10, 5)->default(0);
            $table->decimal('level_2', 10, 5)->default(0);
            $table->decimal('level_3', 10, 5)->default(0);
            $table->decimal('level_4', 10, 5)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_rates');
    }
};
