<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Link;
use Illuminate\Support\Str;

class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition()
    {
        return [
            'user_id' => null, // bisa di-set saat testing
            'original_url' => $this->faker->url(),
            'code' => strtoupper(Str::random(7)),
            'title' => $this->faker->sentence(),
            'status' => 'active',
            'earn_per_click' => 0.05,
            'token' => null,
            'token_created_at' => null,
        ];
    }
}
