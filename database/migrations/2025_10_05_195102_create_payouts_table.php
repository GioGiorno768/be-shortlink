<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Added from other migrations
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            
            $table->decimal('amount', 10, 2);
            $table->decimal('fee', 10, 2)->default(0);
            
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid', 'cancelled'])->default('pending');
            
            $table->string('method')->nullable(); // e.g., 'paypal', 'bank_transfer' (snapshot)
            $table->text('account_details')->nullable(); // Snapshot of account details
            
            $table->string('transaction_id')->nullable();
            
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
