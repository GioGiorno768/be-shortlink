<?php

namespace Database\Seeders;

use App\Models\ViolationReferrer;
use Illuminate\Database\Seeder;

class ViolationReferrerSeeder extends Seeder
{
    /**
     * Common URL shortener domains that should be blocked.
     */
    protected array $commonShorteners = [
        ['domain' => 's.id', 'name' => 'S.ID'],
        ['domain' => 'bit.ly', 'name' => 'Bitly'],
        ['domain' => 'bitly.com', 'name' => 'Bitly'],
        ['domain' => 'tinyurl.com', 'name' => 'TinyURL'],
        ['domain' => 'rb.gy', 'name' => 'Rebrandly'],
        ['domain' => 'cutt.ly', 'name' => 'Cuttly'],
        ['domain' => 'ow.ly', 'name' => 'Hootsuite'],
        ['domain' => 't.co', 'name' => 'Twitter'],
        ['domain' => 'goo.gl', 'name' => 'Google (deprecated)'],
        ['domain' => 'adf.ly', 'name' => 'AdFly'],
        ['domain' => 'shorte.st', 'name' => 'Shorte.st'],
        ['domain' => 'ouo.io', 'name' => 'Ouo.io'],
        ['domain' => 'za.gl', 'name' => 'Za.gl'],
        ['domain' => 'exe.io', 'name' => 'Exe.io'],
        ['domain' => 'linkvertise.com', 'name' => 'Linkvertise'],
        ['domain' => 'bc.vc', 'name' => 'Bc.vc'],
        ['domain' => 'shrinkme.io', 'name' => 'ShrinkMe'],
        ['domain' => 'shorturl.at', 'name' => 'ShortURL'],
        ['domain' => 'is.gd', 'name' => 'Is.gd'],
        ['domain' => 'v.gd', 'name' => 'V.gd'],
        ['domain' => 'clk.sh', 'name' => 'Clk.sh'],
        ['domain' => 'shorten.asia', 'name' => 'Shorten.asia'],
        ['domain' => 'sh.st', 'name' => 'AdF.ly (sh.st)'],
        ['domain' => 'adfoc.us', 'name' => 'AdFocus'],
        ['domain' => 'linkshrink.net', 'name' => 'LinkShrink'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->commonShorteners as $shortener) {
            ViolationReferrer::updateOrCreate(
                ['domain' => $shortener['domain']],
                [
                    'name' => $shortener['name'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Seeded ' . count($this->commonShorteners) . ' violation referrers.');
    }
}
