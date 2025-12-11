<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired notifications from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Pruning expired notifications...');

        // Hapus notifikasi yang expires_at-nya tidak null DAN kurang dari waktu sekarang
        // Karena expires_at ada di dalam kolom JSON 'data', kita pakai whereRaw atau whereJson
        
        $deleted = DB::table('notifications')
            ->whereNotNull('data->expires_at')
            ->where('data->expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$deleted} expired notifications.");
    }
}
