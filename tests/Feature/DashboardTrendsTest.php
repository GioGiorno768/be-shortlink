<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Link;
use App\Models\View;
use App\Models\UserDailyStat;
use Carbon\Carbon;

/**
 * Test untuk memastikan DashboardController::trends() 
 * mengembalikan struktur JSON yang benar.
 * 
 * PENTING: Test ini memvalidasi backward compatibility dengan frontend.
 */
class DashboardTrendsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test bahwa trends endpoint mengembalikan struktur yang benar
     */
    public function test_trends_endpoint_returns_correct_structure(): void
    {
        // 1. Setup User
        $user = User::factory()->create();

        // 2. Setup Link
        $link = Link::create([
            'user_id' => $user->id,
            'original_url' => 'https://example.com',
            'code' => 'testtrends',
            'title' => 'Test Link for Trends',
        ]);

        // 3. Seed Views for the past week
        $today = Carbon::today();
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->subDays($i);
            
            // Create 3 views per day (2 valid, 1 invalid)
            for ($j = 0; $j < 2; $j++) {
                $view = View::create([
                    'link_id' => $link->id,
                    'ip_address' => "127.0.0.{$i}",
                    'is_valid' => true,
                    'earned' => 0.05,
                ]);
                $view->created_at = $date->copy()->addHours($j);
                $view->save();
            }
            
            // 1 invalid view
            $invalidView = View::create([
                'link_id' => $link->id,
                'ip_address' => "127.0.0.{$i}",
                'is_valid' => false,
                'earned' => 0,
            ]);
            $invalidView->created_at = $date->copy()->addHours(10);
            $invalidView->save();
        }

        // 4. Call Endpoint
        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/trends?period=weekly');

        // 5. Assert Response Structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'period',
                    'link',
                    'trends' => [
                        '*' => [
                            'date',
                            'label',
                            'earnings',
                            'clicks',
                            'valid_clicks',
                        ]
                    ]
                ]
            ]);

        // 6. Assert Data Types
        $data = $response->json('data');
        
        $this->assertIsString($data['period']);
        $this->assertIsArray($data['trends']);
        
        if (count($data['trends']) > 0) {
            $firstTrend = $data['trends'][0];
            $this->assertIsString($firstTrend['date']);
            $this->assertIsString($firstTrend['label']);
            $this->assertTrue(
                is_float($firstTrend['earnings']) || is_int($firstTrend['earnings']),
                'earnings should be numeric'
            );
            $this->assertIsInt($firstTrend['clicks']);
            $this->assertIsInt($firstTrend['valid_clicks']);
        }
    }

    /**
     * Test bahwa trends dengan link filter tetap bekerja
     */
    public function test_trends_endpoint_with_link_filter(): void
    {
        $user = User::factory()->create();

        $link = Link::create([
            'user_id' => $user->id,
            'original_url' => 'https://example.com',
            'code' => 'filteredlink',
            'title' => 'Filtered Link',
        ]);

        // Create a view for today
        View::create([
            'link_id' => $link->id,
            'ip_address' => '127.0.0.1',
            'is_valid' => true,
            'earned' => 0.10,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/dashboard/trends?period=weekly&link={$link->code}");

        $response->assertStatus(200)
            ->assertJsonPath('data.link', $link->code)
            ->assertJsonPath('data.period', 'weekly');
    }

    /**
     * Test bahwa endpoint memerlukan authentication
     */
    public function test_trends_requires_authentication(): void
    {
        $response = $this->getJson('/api/dashboard/trends');

        $response->assertStatus(401);
    }

    /**
     * Test periode daily
     */
    public function test_trends_with_daily_period(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/trends?period=daily');

        $response->assertStatus(200)
            ->assertJsonPath('data.period', 'daily');
    }

    /**
     * Test periode monthly  
     */
    public function test_trends_with_monthly_period(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/trends?period=monthly');

        $response->assertStatus(200)
            ->assertJsonPath('data.period', 'monthly');
    }
}
