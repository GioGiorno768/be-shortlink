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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('method_type'); // e.g., 'bank_transfer', 'paypal', 'ewallet'
            
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bank_name')->nullable(); // For bank transfer
            
            $table->decimal('fee', 10, 2)->default(0);
            $table->boolean('is_verified')->default(false);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
