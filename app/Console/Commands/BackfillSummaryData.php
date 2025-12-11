<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Link;
use App\Models\View;
use Illuminate\Support\Facades\DB;

class BackfillSummaryData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-summary-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill summary columns for users and links';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill process...');

        // 1. Backfill Links
        $this->info('Backfilling Links...');
        $links = Link::all();
        $bar = $this->output->createProgressBar(count($links));
        $bar->start();

        foreach ($links as $link) {
            $views = View::where('link_id', $link->id)->count();
            $validViews = View::where('link_id', $link->id)->where('is_unique', true)->count(); // Assuming is_unique implies valid for stats

            $link->update([
                'views' => $views,
                'valid_views' => $validViews,
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // 2. Backfill Users
        $this->info('Backfilling Users...');
        $users = User::all();
        $bar = $this->output->createProgressBar(count($users));
        $bar->start();

        foreach ($users as $user) {
            // Calculate views from user's links
            $totalViews = 0;
            $totalValidViews = 0;
            
            // Efficient way: Sum from links table (since we just updated it)
            $totalViews = Link::where('user_id', $user->id)->sum('views');
            $totalValidViews = Link::where('user_id', $user->id)->sum('valid_views');

            // Calculate referrals
            $totalReferrals = User::where('referred_by', $user->id)->count();

            $user->update([
                'total_views' => $totalViews,
                'total_valid_views' => $totalValidViews,
                'total_referrals' => $totalReferrals,
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info('Backfill completed successfully!');
    }
}
