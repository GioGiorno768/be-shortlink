<?php

namespace App\Console\Commands;

use App\Models\Level;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncUserLevels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:sync-levels 
                            {--user= : Sync specific user by ID}
                            {--dry-run : Show what would change without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync user levels based on their total_earnings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Syncing user levels...');

        // Clear level cache first
        Cache::forget('all_levels');

        // Get all levels ordered by min_total_earnings DESC
        $levels = Level::orderBy('min_total_earnings', 'desc')->get();

        if ($levels->isEmpty()) {
            $this->error('No levels found in database!');
            return 1;
        }

        $this->info("📊 Level tiers:");
        foreach ($levels as $level) {
            $this->line("   - {$level->name}: \${$level->min_total_earnings}+ (max_referrals: {$level->max_referrals})");
        }
        $this->newLine();

        // Get users to sync
        $query = User::where('role', 'user');

        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }

        $users = $query->get();
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $updated = 0;
        $unchanged = 0;

        foreach ($users as $user) {
            // Find correct level based on total_earnings
            $correctLevel = $levels->firstWhere('min_total_earnings', '<=', $user->total_earnings);

            if (!$correctLevel) {
                // Default to lowest level (Beginner)
                $correctLevel = $levels->last();
            }

            $currentLevelName = $levels->firstWhere('id', $user->current_level_id)?->name ?? 'NULL';

            if ($user->current_level_id !== $correctLevel->id) {
                $this->line("📈 {$user->name} (#{$user->id}):");
                $this->line("   Earnings: \${$user->total_earnings}");
                $this->line("   Current: {$currentLevelName} -> New: {$correctLevel->name}");

                if (!$isDryRun) {
                    $user->update(['current_level_id' => $correctLevel->id]);
                }
                $updated++;
            } else {
                $unchanged++;
            }
        }

        $this->newLine();
        $this->info("✅ Summary:");
        $this->line("   Updated: {$updated} users");
        $this->line("   Unchanged: {$unchanged} users");

        if ($isDryRun && $updated > 0) {
            $this->newLine();
            $this->warn("Run without --dry-run to apply changes.");
        }

        return 0;
    }
}
