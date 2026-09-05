<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mobile_only_payload_creates_user_with_nullable_email(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'mobile' => '09111111111',
        ]);

        $response = $this
            ->withSession(['user_id' => $admin->id])
            ->postJson('/api/users', [
                'name' => 'یوسف سادات فخر',
                'mobile' => '09205850190',
                'can_add_users' => false,
                'can_add_products' => false,
                'can_change_balance' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'یوسف سادات فخر')
            ->assertJsonPath('mobile', '09205850190')
            ->assertJsonPath('email', null);

        $this->assertDatabaseHas('users', [
            'name' => 'یوسف سادات فخر',
            'mobile' => '09205850190',
            'email' => null,
            'can_change_balance' => true,
        ]);
    }
}
