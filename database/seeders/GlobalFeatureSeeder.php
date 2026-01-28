<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GlobalFeature;
use App\Models\AdLevelConfig;

class GlobalFeatureSeeder extends Seeder
{
    /**
     * Seed global features from existing ad_level_configs features
     */
    public function run(): void
    {
        // Define unique features that exist across all levels
        $features = [
            ['name' => 'Banner Ads', 'description' => 'Display banner advertisements', 'display_order' => 1],
            ['name' => 'Interstitial', 'description' => 'Full-screen interstitial ads', 'display_order' => 2],
            ['name' => 'Popunder', 'description' => 'Pop-under advertisements', 'display_order' => 3],
            ['name' => 'Push Notif', 'description' => 'Push notification ads', 'display_order' => 4],
            ['name' => 'Captcha', 'description' => 'Captcha verification requirement', 'display_order' => 5],
        ];

        foreach ($features as $feature) {
            GlobalFeature::updateOrCreate(
                ['name' => $feature['name']],
                $feature
            );
        }

        // Now update each ad level's enabled_features based on existing features JSON
        $levels = AdLevelConfig::all();
        $globalFeatures = GlobalFeature::all()->keyBy('name');

        foreach ($levels as $level) {
            $existingFeatures = $level->features ?? [];
            $enabledFeatureIds = [];

            foreach ($existingFeatures as $feature) {
                if (isset($feature['included']) && $feature['included'] === true) {
                    $globalFeature = $globalFeatures->get($feature['label']);
                    if ($globalFeature) {
                        $enabledFeatureIds[] = (string) $globalFeature->id;
                    }
                }
            }

            $level->enabled_features = $enabledFeatureIds;
            $level->save();
        }

        $this->command->info('Global features seeded and ad levels updated!');
    }
}
