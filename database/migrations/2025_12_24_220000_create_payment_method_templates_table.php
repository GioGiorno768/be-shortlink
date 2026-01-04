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
        Schema::create('payment_method_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->enum('type', ['wallet', 'bank', 'crypto']);
            $table->char('currency', 3); // ISO code: IDR, USD
            $table->enum('input_type', ['phone', 'email', 'account_number', 'crypto_address']);
            $table->string('input_label', 50);
            $table->string('icon', 100)->nullable();
            $table->decimal('fee', 18, 8)->default(0);
            $table->decimal('min_amount', 18, 8)->default(0);
            $table->decimal('max_amount', 18, 8)->default(0); // 0 = no limit
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Indexes for performance
            $table->index(['is_active', 'sort_order']);
            $table->index('type');

            // Prevent duplicate methods
            $table->unique(['name', 'currency', 'type']);
        });

        // Add template_id to existing payment_methods table
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('template_id')
                ->nullable()
                ->after('id')
                ->constrained('payment_method_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn('template_id');
        });

        Schema::dropIfExists('payment_method_templates');
    }
};
