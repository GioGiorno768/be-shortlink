<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Link;
use App\Models\View;
use App\Models\Level;
use Carbon\Carbon;

class MonthlyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_performance_endpoint_returns_correct_structure()
    {
        // 1. Setup User & Levels
        $user = User::factory()->create();
        
        Level::create(['name' => 'Basic', 'min_total_earnings' => 0, 'bonus_percentage' => 0]);
        Level::create(['name' => 'Intermediate', 'min_total_earnings' => 10, 'bonus_percentage' => 5]);
        Level::create(['name' => 'Advanced', 'min_total_earnings' => 50, 'bonus_percentage' => 10]);

        // 2. Setup Link
        $link = Link::create([
            'user_id' => $user->id,
            'original_url' => 'https://example.com',
            'code' => 'testcode',
            'title' => 'Test Link'
        ]);

        // 3. Seed Views (Past Month & Current Month)
        // Past Month: 5 views, $1 each -> $5 total. Cumulative: $5 (Basic)
        for ($i = 0; $i < 5; $i++) {
            $view = View::create([
                'link_id' => $link->id,
                'ip_address' => '127.0.0.1',
                'is_valid' => true,
                'earned' => 1.00,
            ]);
            $view->created_at = Carbon::now()->subMonth()->startOfMonth()->addDays($i);
            $view->save();
        }

        // Current Month: 10 views, $1 each -> $10 total. Cumulative: $5 + $10 = $15 (Intermediate)
        for ($i = 0; $i < 10; $i++) {
            $view = View::create([
                'link_id' => $link->id,
                'ip_address' => '127.0.0.1',
                'is_valid' => true,
                'earned' => 1.00,
            ]);
            $view->created_at = Carbon::now()->startOfMonth()->addDays($i);
            $view->save();
        }

        // 4. Call Endpoint
        $response = $this->actingAs($user)
                         ->getJson('/api/analytics/monthly-performance?range=6months');

        // 5. Assertions
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'range_info',
                         'items' => [
                             '*' => [
                                 'month',
                                 'label',
                                 'valid_clicks',
                                 'earnings',
                                 'average_cpm',
                                 'user_level'
                             ]
                         ]
                     ]
                 ]);

        // Verify Data Content
        $data = $response->json('data.items');
        
        // Find Past Month Data
        $pastMonthKey = Carbon::now()->subMonth()->format('Y-m');
        $pastMonthData = collect($data)->firstWhere('month', $pastMonthKey);
        
        $this->assertNotNull($pastMonthData, 'Past month data not found');
        $this->assertEquals(5, $pastMonthData['valid_clicks']);
        $this->assertEquals(5.00, $pastMonthData['earnings']);
        $this->assertEquals('Basic', $pastMonthData['user_level']); // Cumulative $5 < $10

        // Find Current Month Data
        $currentMonthKey = Carbon::now()->format('Y-m');
        $currentMonthData = collect($data)->firstWhere('month', $currentMonthKey);
        
        $this->assertNotNull($currentMonthData, 'Current month data not found');
        $this->assertEquals(10, $currentMonthData['valid_clicks']);
        $this->assertEquals(10.00, $currentMonthData['earnings']);
        $this->assertEquals('Intermediate', $currentMonthData['user_level']); // Cumulative $15 >= $10
    }
}
