<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Models\Link;

class SyncRedisStats extends Command
{
    protected $signature = 'stats:sync';
    protected $description = 'Sync Redis views count into database';

    public function handle()
    {
        $keys = Redis::keys('link:*:views');

        foreach ($keys as $key) {
            $linkId = (int) str_replace(['link:', ':views'], '', $key);
            $views = (int) Redis::get($key);

            $link = Link::find($linkId);
            if ($link) {
                $link->increment('views', $views);
                Redis::del($key);
            }
        }

        $this->info('Synced Redis stats to database successfully.');
    }
}
