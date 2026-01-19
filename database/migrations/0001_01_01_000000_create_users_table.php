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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            
            // Added columns from other migrations
            $table->string('referral_code')->nullable()->unique();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
            
            $table->decimal('balance', 10, 2)->default(0);
            $table->decimal('pending_balance', 10, 2)->default(0);
            $table->decimal('total_earnings', 10, 4)->default(0);
            
            $table->string('role')->default('user');
            $table->boolean('is_banned')->default(false);
            
            // Socialite
            $table->string('google_id')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('avatar')->nullable();
            
            $table->string('phone_number')->nullable();
            
            // Summary columns
            $table->unsignedBigInteger('total_views')->default(0);
            $table->unsignedBigInteger('total_valid_views')->default(0);
            
            $table->timestamp('last_active_at')->nullable();
            
            $table->foreignId('current_level_id')->nullable()->constrained('levels')->nullOnDelete();
            
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
