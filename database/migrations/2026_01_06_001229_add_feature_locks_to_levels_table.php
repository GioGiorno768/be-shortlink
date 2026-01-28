<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            // Feature unlock flags
            $table->boolean('unlock_ad_level_3')->default(false)->after('border_color');
            $table->boolean('unlock_ad_level_4')->default(false)->after('unlock_ad_level_3');
            $table->boolean('unlock_top_countries')->default(false)->after('unlock_ad_level_4');
            $table->boolean('unlock_top_referrers')->default(false)->after('unlock_top_countries');

            // Limits (-1 = unlimited)
            $table->integer('max_referrals')->default(10)->after('unlock_top_referrers');
            $table->decimal('monthly_withdrawal_limit', 15, 2)->default(100.00)->after('max_referrals');
        });

        // Set default values based on level tier
        $this->setDefaultFeatureLocks();
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn([
                'unlock_ad_level_3',
                'unlock_ad_level_4',
                'unlock_top_countries',
                'unlock_top_referrers',
                'max_referrals',
                'monthly_withdrawal_limit',
            ]);
        });
    }

    /**
     * Set sensible defaults based on level earnings threshold
     */
    private function setDefaultFeatureLocks(): void
    {
        $levels = \App\Models\Level::orderBy('min_total_earnings', 'asc')->get();

        $defaults = [
            // level index => [ad3, ad4, countries, referrers, max_refs, withdrawal]
            0 => [false, false, false, false, 5, 25],       // Beginner
            1 => [false, false, false, false, 10, 50],      // Rookie
            2 => [true, false, true, false, 20, 100],       // Elite
            3 => [true, true, true, false, 50, 500],        // Pro
            4 => [true, true, true, true, 100, 2000],       // Master
            5 => [true, true, true, true, -1, -1],          // Mythic (unlimited)
        ];

        foreach ($levels as $index => $level) {
            $config = $defaults[$index] ?? $defaults[0];

            $level->update([
                'unlock_ad_level_3' => $config[0],
                'unlock_ad_level_4' => $config[1],
                'unlock_top_countries' => $config[2],
                'unlock_top_referrers' => $config[3],
                'max_referrals' => $config[4],
                'monthly_withdrawal_limit' => $config[5],
            ]);
        }
    }
};
