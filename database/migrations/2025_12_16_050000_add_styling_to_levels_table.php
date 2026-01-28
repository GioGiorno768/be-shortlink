<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name'); // beginner, rookie, etc.
            $table->string('icon')->default('shield')->after('slug'); // lucide icon name
            $table->json('benefits')->nullable()->after('bonus_percentage'); // ["Benefit 1", "Benefit 2"]
            $table->string('icon_color')->default('text-gray-500')->after('benefits');
            $table->string('bg_color')->default('bg-white')->after('icon_color');
            $table->string('border_color')->default('border-gray-200')->after('bg_color');
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn(['slug', 'icon', 'benefits', 'icon_color', 'bg_color', 'border_color']);
        });
    }
};
