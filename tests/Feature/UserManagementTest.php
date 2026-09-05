<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->member = User::factory()->create(['mobile' => '09222222222']);
    }

    public function test_manager_updates_user_info_and_resets_password(): void
    {
        $this->withSession(['user_id' => $this->admin->id])
            ->putJson("/api/users/{$this->member->id}", [
                'name' => 'نام تازه',
                'mobile' => '09333333333',
                'password' => 'newpassword1',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'نام تازه')
            ->assertJsonPath('mobile', '09333333333');

        $this->assertTrue(Hash::check('newpassword1', $this->member->fresh()->password));
    }

    public function test_duplicate_mobile_is_rejected(): void
    {
        $this->withSession(['user_id' => $this->admin->id])
            ->putJson("/api/users/{$this->member->id}", [
                'name' => 'x',
                'mobile' => $this->admin->mobile,
            ])
            ->assertStatus(422);
    }

    public function test_admin_account_cannot_be_edited(): void
    {
        $this->withSession(['user_id' => $this->admin->id])
            ->putJson("/api/users/{$this->admin->id}", ['name' => 'x', 'mobile' => '09444444444'])
            ->assertStatus(422);
    }

    public function test_user_without_manage_permission_cannot_edit_users(): void
    {
        $this->withSession(['user_id' => $this->member->id])
            ->putJson("/api/users/{$this->member->id}", ['name' => 'x', 'mobile' => '09333333333'])
            ->assertForbidden();
    }

    public function test_user_updates_own_profile_including_mobile(): void
    {
        $this->withSession(['user_id' => $this->member->id])
            ->putJson('/api/profile', [
                'name' => 'خودم',
                'mobile' => '09555555555',
                'password' => 'mypass1234',
            ])
            ->assertOk()
            ->assertJsonPath('mobile', '09555555555');

        $this->assertTrue(Hash::check('mypass1234', $this->member->fresh()->password));
    }

    public function test_user_cannot_take_someone_elses_mobile(): void
    {
        $this->withSession(['user_id' => $this->member->id])
            ->putJson('/api/profile', ['name' => 'x', 'mobile' => $this->admin->mobile])
            ->assertStatus(422);
    }

    public function test_manager_updates_member_permissions(): void
    {
        $this->withSession(['user_id' => $this->admin->id])
            ->putJson("/api/users/{$this->member->id}/permissions", [
                'can_add_users' => true,
                'can_add_products' => true,
                'can_edit_products' => true,
                'can_change_balance' => true,
                'can_manage_permissions' => false,
            ])
            ->assertOk()
            ->assertJsonPath('can_edit_products', true);

        $this->assertTrue((bool) $this->member->fresh()->can_edit_products);
    }
}
