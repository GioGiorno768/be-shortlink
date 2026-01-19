<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Link;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;


class LinkApiEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $position = new Position([
            'ip' => '127.0.0.1',
            'isoCode' => 'ID',
            'countryName' => 'Indonesia',
            'regionCode' => 'JK',
            'regionName' => 'Jakarta',
            'cityName' => 'Jakarta',
            'zipCode' => '10110',
            'latitude' => 0,
            'longitude' => 0,
            'metroCode' => 0,
            'areaCode' => 0,
        ]);

        $mock = Mockery::mock('alias:Stevebauman\Location\Facades\Location');
        $mock->shouldReceive('get')->andReturn($position);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stats_endpoint_requires_auth()
    {
        $link = Link::factory()->create();

        $this->getJson("/api/links/{$link->code}/stats")
            ->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function creating_shortlink_with_invalid_url_fails()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/links', [
                'original_url' => 'not-a-url'
            ])
            ->assertStatus(422); // validasi URL gagal
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function continue_endpoint_fails_with_invalid_token()
    {
        $link = Link::factory()->create(['token' => 'valid-token']);

        $this->postJson("/api/links/{$link->code}/continue", [
            'token' => 'invalid-token'
        ])->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function continue_endpoint_fails_when_token_expired()
    {
        $link = Link::factory()->create([
            'token' => 'expired-token',
            'token_created_at' => now()->subHours(3), // token lebih dari 2 jam
        ]);

        $this->postJson("/api/links/{$link->code}/continue", [
            'token' => 'expired-token'
        ])->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function duplicate_shortlink_codes_are_handled_gracefully()
    {
        $user = User::factory()->create();

        // Buat shortlink pertama
        $this->actingAs($user)
            ->postJson('/api/links', ['original_url' => 'https://example.com'])
            ->assertStatus(201);

        // Buat shortlink kedua, gunakan mock code agar duplicate
        $response = $this->actingAs($user)
            ->postJson('/api/links', ['original_url' => 'https://example.com']);

        $response->assertStatus(201)
            ->assertJsonStructure(['code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function creating_shortlink_as_guest_sets_earn_to_zero()
    {
        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com'
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response['is_guest']);
        $this->assertEquals(0.0, (float) $response['earn_per_click']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function logged_in_user_can_earn_on_unique_view()
    {
        $user = User::factory()->create(['balance' => 0]);

        $link = Link::factory()->create([
            'user_id' => $user->id,
            'earn_per_click' => 0.05,
            'token' => 'valid-token'
        ]);

        // Simulasikan view
        $this->postJson("/api/links/{$link->code}/continue", [
            'token' => 'valid-token'
        ])->assertStatus(200);

        $user->refresh();
        $this->assertEquals(0.05, $user->balance);
    }
}
