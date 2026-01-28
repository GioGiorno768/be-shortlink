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
        Schema::create('link_reports', function (Blueprint $table) {
            $table->id();
            $table->string('link_url'); // URL yang dilaporkan
            $table->unsignedBigInteger('link_id')->nullable(); // ID Link jika ditemukan di sistem
            $table->string('reason'); // Alasan (Scam, Spam, dll)
            $table->string('email')->nullable(); // Email pelapor (opsional)
            $table->text('details')->nullable(); // Detail tambahan
            $table->string('ip_address'); // IP Pelapor (untuk rate limiting)
            $table->timestamps();

            // Index untuk pencarian cepat
            $table->index('link_id');
            $table->index('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_reports');
    }
};
