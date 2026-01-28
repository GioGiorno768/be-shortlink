<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Payout;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_processing_logs_admin()
    {
        // 1. Setup Data
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        
        // Create Payment Method first
        $paymentMethod = PaymentMethod::create([
            'user_id' => $user->id,
            'type' => 'bank',
            'account_name' => 'Test Account',
            'account_number' => '1234567890',
            'is_verified' => true,
        ]);

        $payout = Payout::create([
            'user_id' => $user->id,
            'amount' => 100,
            'status' => 'pending',
            'payment_method_id' => $paymentMethod->id,
            'fee' => 0,
        ]);

        // 2. Admin Approves Withdrawal
        $response = $this->actingAs($admin)->putJson("/api/admin/withdrawals/{$payout->id}/status", [
            'status' => 'approved',
            'notes' => 'Approved by admin',
        ]);

        $response->assertStatus(200);

        // 3. Verify processed_by is set
        $this->assertDatabaseHas('payouts', [
            'id' => $payout->id,
            'status' => 'approved',
            'processed_by' => $admin->id,
        ]);
    }

    public function test_super_admin_can_view_logs()
    {
        // 1. Setup Data
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        // Create Payment Method first
        $paymentMethod = PaymentMethod::create([
            'user_id' => $user->id,
            'type' => 'bank',
            'account_name' => 'Test Account',
            'account_number' => '1234567890',
            'is_verified' => true,
        ]);

        $payout = Payout::create([
            'user_id' => $user->id,
            'amount' => 100,
            'status' => 'approved',
            'processed_by' => $admin->id,
            'payment_method_id' => $paymentMethod->id,
            'fee' => 0,
        ]);

        // 2. Super Admin fetches logs
        $response = $this->actingAs($superAdmin)->getJson('/api/super-admin/withdrawal-logs');

        $response->assertStatus(200)
            ->assertJsonFragment(['processed_by' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'email_verified_at' => $admin->email_verified_at->toISOString(), 'created_at' => $admin->created_at->toISOString(), 'updated_at' => $admin->updated_at->toISOString(), 'role' => 'admin', 'has_password' => true]]);
    }
}
