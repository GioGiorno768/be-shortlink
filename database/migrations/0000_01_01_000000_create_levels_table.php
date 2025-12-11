<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Contoh: Level 1, Level 2
            $table->decimal('min_total_earnings', 15, 2)->default(0); // Batas pendapatan (misal: 2000000)
            $table->decimal('bonus_percentage', 5, 2)->default(0); // Bonus CPM (misal: 5.00)
            $table->timestamps();
        });

        // Opsional: Langsung isi data default (Seeder) di sini atau buat class Seeder terpisah
    }

    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};