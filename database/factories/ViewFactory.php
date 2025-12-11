<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\View;
use App\Models\Link;

class ViewFactory extends Factory
{
    protected $model = View::class;

    public function definition()
    {
        return [
            'link_id' => Link::factory(),
            'ip_address' => $this->faker->ipv4(),
            'country' => $this->faker->country(),
            'device' => 'desktop',
            'browser' => 'Chrome',
            'referer' => $this->faker->url(),
            'is_unique' => true,
            'is_valid' => true,
            'earned' => 0.05,
        ];
    }
}
