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
             Schema::table('users', function (Blueprint $table) {
                // Tambahkan provider_name untuk melacak (email, google, etc)
                $table->string('provider_name')->after('remember_token')->nullable();
                // Tambahkan google_id, harus unik dan nullable
                $table->string('google_id')->after('provider_name')->nullable()->unique();
                
                // Ubah password agar nullable jika belum (meskipun di migrasi Anda sudah)
                $table->string('password')->nullable()->change(); 
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['provider_name', 'google_id']);
            });
    }
};
