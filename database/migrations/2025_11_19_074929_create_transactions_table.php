<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Jenis transaksi: 'referral_commission', 'withdrawal', 'ad_revenue', 'adjustment'
            $table->string('type'); 
            // Jumlah uang (bisa positif atau negatif)
            $table->decimal('amount', 16, 2); 
            // Deskripsi agar user paham ini uang apa
            $table->string('description'); 
            // (Opsional) ID referensi ke tabel lain (misal: id withdrawal)
            $table->unsignedBigInteger('reference_id')->nullable(); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};