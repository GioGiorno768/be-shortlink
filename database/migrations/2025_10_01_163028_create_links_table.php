<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('original_url');
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->enum('status', ['active', 'disabled'])->default('active');
            
            $table->string('password')->nullable();
            $table->timestamp('expired_at')->nullable();
            
            $table->text('admin_comment')->nullable();
            
            $table->integer('ad_level')->default(1);
            $table->decimal('earn_per_click', 10, 5)->default(0); // Increased precision
            $table->decimal('total_earned', 10, 4)->default(0);
            
            $table->string('creator_ip')->nullable(); // Renamed from ip_address to match usage if any, or just ip_address? 
            // Checking add_ip_address migration... it says 'creator_ip'.
            
            $table->boolean('is_banned')->default(false);
            $table->text('ban_reason')->nullable();
            
            // Summary columns
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('valid_views')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
