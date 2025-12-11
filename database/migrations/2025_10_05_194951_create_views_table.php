<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->string('country')->nullable();
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_valid')->default(true);
            $table->decimal('earned', 10, 5)->default(0);
            $table->text('note')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['link_id', 'created_at']); 
            $table->index(['ip_address', 'created_at']);
            $table->index('is_valid');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('views');
    }
};
