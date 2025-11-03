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
            // Tambahkan kolom total_earned dengan nilai default 0
            $table->decimal('total_earned', 12, 2)->default(0)->after('earn_per_click')
                  ->comment('Akumulasi total pendapatan dari klik valid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('total_earned');
        });
    }
};
