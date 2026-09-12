<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $viewer;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->viewer = User::factory()->create(['mobile' => '09222222222', 'can_view_logs' => true]);
        $this->member = User::factory()->create(['mobile' => '09333333333']);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }

    public function test_successful_login_is_logged(): void
    {
        $this->postJson('/api/login', ['mobile' => $this->admin->mobile, 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => ActivityLog::ACTION_LOGIN,
        ]);
    }

    public function test_failed_login_is_logged_without_user(): void
    {
        $this->postJson('/api/login', ['mobile' => '09999999999', 'password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => null,
            'action' => ActivityLog::ACTION_LOGIN_FAILED,
        ]);
    }

    public function test_logout_is_logged(): void
    {
        $this->actingAsSession($this->admin)->postJson('/api/logout')->assertNoContent();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => ActivityLog::ACTION_LOGOUT,
        ]);
    }

    public function test_product_creation_is_logged(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products', ['name' => 'طلا', 'quantity' => 5, 'unit' => 'گرم'])
            ->assertStatus(201);

        $log = ActivityLog::where('action', ActivityLog::ACTION_PRODUCT_CREATE)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('افزودن کالا طلا', $log->summary);
        $this->assertSame(5.0, (float) $log->details['quantity']);
    }

    public function test_trade_and_adjustment_are_logged(): void
    {
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$product->id}/balance", [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 100,
                'trade_date' => '1405/06/01',
                'settlement_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
            ])
            ->assertStatus(201);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$product->id}/balance", [
                'amount' => 3,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::ACTION_TRADE]);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::ACTION_ADJUST]);
    }

    public function test_trade_summary_uses_slash_as_decimal_separator(): void
    {
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$product->id}/balance", [
                'direction' => 'خرید',
                'quantity' => 2.5,
                'unit_price' => 100.25,
                'trade_date' => '1405/06/01',
                'settlement_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
            ])
            ->assertStatus(201);

        $log = ActivityLog::where('action', ActivityLog::ACTION_TRADE)->first();
        $this->assertNotNull($log);
        $this->assertSame('خرید 2/5 طلا به قیمت واحد 100/25 (ثبت در تراز)', $log->summary);
    }

    public function test_person_and_user_actions_are_logged(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/persons', ['name' => 'علی'])
            ->assertStatus(201);

        $this->actingAsSession($this->admin)
            ->postJson('/api/users', ['name' => 'کاربر تازه', 'mobile' => '09444444444'])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::ACTION_PERSON_CREATE]);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::ACTION_USER_CREATE]);
    }

    public function test_permission_change_is_logged_with_details(): void
    {
        $this->actingAsSession($this->admin)
            ->putJson("/api/users/{$this->member->id}/permissions", ['can_change_balance' => true])
            ->assertOk();

        $log = ActivityLog::where('action', ActivityLog::ACTION_PERMISSION_UPDATE)->first();
        $this->assertNotNull($log);
        $this->assertNotEmpty($log->details['changes']);
    }

    public function test_logs_require_view_permission(): void
    {
        $this->actingAsSession($this->member)->getJson('/api/logs')->assertForbidden();
        $this->actingAsSession($this->member)->getJson('/api/logs/options')->assertForbidden();
    }

    public function test_viewer_can_filter_logs(): void
    {
        ActivityLog::create([
            'user_id' => $this->admin->id,
            'action' => ActivityLog::ACTION_LOGIN,
            'summary' => 'ورود',
        ]);
        ActivityLog::create([
            'user_id' => $this->viewer->id,
            'action' => ActivityLog::ACTION_LOGOUT,
            'summary' => 'خروج',
        ]);

        $response = $this->actingAsSession($this->viewer)
            ->getJson('/api/logs?user_id='.$this->admin->id)
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['total']);
        $this->assertSame(ActivityLog::ACTION_LOGIN, $response['data'][0]['action']);
    }

    public function test_invalid_jalali_filter_returns_422(): void
    {
        $this->actingAsSession($this->viewer)
            ->getJson('/api/logs?from=1405/13/01')
            ->assertStatus(422);
    }

    public function test_person_deletion_is_logged(): void
    {
        $person = Person::create(['name' => 'علی']);

        $this->actingAsSession($this->admin)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertOk();

        $log = ActivityLog::where('action', ActivityLog::ACTION_PERSON_DELETE)->first();
        $this->assertNotNull($log);
        $this->assertSame('حذف شخص علی', $log->summary);
        $this->assertSame($this->admin->id, $log->user_id);
    }
}
