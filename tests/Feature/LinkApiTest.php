<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Link;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Carbon\Carbon;

class LinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Location dengan tipe Position
        $position = new Position([
            'ip' => '127.0.0.1',
            'countryName' => 'Indonesia',
            'countryCode' => 'ID',
            'regionName' => 'Jakarta',
            'cityName' => 'Jakarta',
            'zipCode' => '10110',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'Asia/Jakarta',
            'continentName' => 'Asia',
            'default' => false,
        ]);

        Location::shouldReceive('get')->andReturn($position);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_can_register_and_login()
    {
        // Register
        $register = $this->postJson('/api/register', [
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'password123',
        ]);

        $register->assertStatus(201)
                 ->assertJsonStructure(['user', 'token']);

        // Login
        $login = $this->postJson('/api/login', [
            'email' => 'tester@example.com',
            'password' => 'password123',
        ]);

        $login->assertStatus(200)
              ->assertJsonStructure(['user', 'token']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function logged_in_user_can_create_shortlink()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/links', [
                             'original_url' => 'https://example.com'
                         ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['short_url', 'code', 'is_guest', 'message'])
                 ->assertJson(['is_guest' => false]);

        $this->assertDatabaseHas('links', [
            'original_url' => 'https://example.com',
            'user_id' => $user->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guest_can_create_shortlink()
    {
        $response = $this->postJson('/api/links', [
            'original_url' => 'https://guest.com'
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['short_url', 'code', 'is_guest', 'message'])
                 ->assertJson(['is_guest' => true]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function full_shortlink_flow_with_logged_in_user()
    {
        $user = User::factory()->create();

        // 1️⃣ Buat shortlink
        $create = $this->actingAs($user, 'sanctum')
                       ->postJson('/api/links', [
                           'original_url' => 'https://example.com'
                       ]);

        $create->assertStatus(200);
        $code = $create->json('code');

        // 2️⃣ Show token & ads
        $show = $this->getJson("/api/links/{$code}");
        $show->assertStatus(200)
             ->assertJsonStructure(['ads', 'token', 'wait_time']);
        $token = $show->json('token');

        // 3️⃣ Continue (validasi token & catat view)
        $continue = $this->postJson("/api/links/{$code}/continue", [
            'token' => $token
        ]);

        $continue->assertStatus(200)
                 ->assertJsonStructure(['original_url', 'ads', 'message'])
                 ->assertJson(['original_url' => 'https://example.com']);

        // Pastikan saldo user bertambah
        $user->refresh();
        $this->assertEquals(0.05, $user->balance);

        // Pastikan view tercatat
        $this->assertDatabaseHas('views', [
            'link_id' => Link::where('code', $code)->first()->id,
            'is_valid' => true,
            'earned' => 0.05,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function token_expiration_blocks_continue()
    {
        $link = Link::factory()->create([
            'token' => '12345',
            'token_created_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson("/api/links/{$link->code}/continue", [
            'token' => '12345'
        ]);

        $response->assertStatus(403)
                 ->assertJson(['error' => 'Token expired. Please reload the page.']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function duplicate_shortlink_codes_are_handled()
    {
        $user = User::factory()->create();

        // Buat link pertama dengan kode tetap
        Link::factory()->create([
            'user_id' => $user->id,
            'code' => 'DUPLICATE',
        ]);

        // Buat link kedua, system harus generate kode baru
        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/links', [
                             'original_url' => 'https://example2.com',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['short_url', 'code', 'is_guest', 'message'])
                 ->assertJson(['is_guest' => false]);

        $this->assertDatabaseCount('links', 2);
        $this->assertDatabaseMissing('links', ['code' => null]);
    }
}
