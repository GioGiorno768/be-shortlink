<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ad_rates', function (Blueprint $table) {
            $table->json('rates')->after('country')->nullable();
        });

        // Migrate existing data
        $rates = DB::table('ad_rates')->get();
        foreach ($rates as $rate) {
            $jsonRates = [
                'level_1' => $rate->level_1 ?? 0.05,
                'level_2' => $rate->level_2 ?? 0.07,
                'level_3' => $rate->level_3 ?? 0.10,
                'level_4' => $rate->level_4 ?? 0.15,
            ];
            
            DB::table('ad_rates')
                ->where('id', $rate->id)
                ->update(['rates' => json_encode($jsonRates)]);
        }

        Schema::table('ad_rates', function (Blueprint $table) {
            $table->dropColumn(['level_1', 'level_2', 'level_3', 'level_4']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_rates', function (Blueprint $table) {
            $table->decimal('level_1', 10, 5)->default(0.0005);
            $table->decimal('level_2', 10, 5)->default(0.0007);
            $table->decimal('level_3', 10, 5)->default(0.0010);
            $table->decimal('level_4', 10, 5)->default(0.0015);
        });

        // Revert data
        $rates = DB::table('ad_rates')->get();
        foreach ($rates as $rate) {
            $jsonRates = json_decode($rate->rates, true);
            DB::table('ad_rates')
                ->where('id', $rate->id)
                ->update([
                    'level_1' => $jsonRates['level_1'] ?? 0.0005,
                    'level_2' => $jsonRates['level_2'] ?? 0.0007,
                    'level_3' => $jsonRates['level_3'] ?? 0.0010,
                    'level_4' => $jsonRates['level_4'] ?? 0.0015,
                ]);
        }

        Schema::table('ad_rates', function (Blueprint $table) {
            $table->dropColumn('rates');
        });
    }
};
