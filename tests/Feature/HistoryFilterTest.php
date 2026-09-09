<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HistoryFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $clerk;

    private Product $gold;

    private Product $coin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->clerk = User::factory()->create(['mobile' => '09222222222', 'can_change_balance' => true]);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        $this->coin = Product::create(['name' => 'سکه', 'quantity' => 0, 'unit' => 'عدد']);

        $this->actingAsSession($this->admin)->postJson("/api/products/{$this->gold->id}/balance", ['amount' => 50]);
        $this->actingAsSession($this->clerk)->postJson("/api/products/{$this->coin->id}/balance", ['amount' => 5]);
    }

    public function test_filters_by_user(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson("/api/history?user_id={$this->clerk->id}&all=1")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user.id', $this->clerk->id);
    }

    public function test_filters_by_product(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson("/api/history?product_id={$this->gold->id}&all=1")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.product.id', $this->gold->id);
    }

    public function test_filters_by_jalali_date_range(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson('/api/history?from=۱۴۰۵/۰۶/۰۱&to=۱۴۰۵/۰۶/۳۰&all=1')
            ->assertOk()
            ->assertJsonCount(2);

        $this->actingAsSession($this->admin)
            ->getJson('/api/history?from=۱۴۰۵/۰۷/۰۱&to=۱۴۰۵/۰۷/۳۰&all=1')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_paginates_ten_records_per_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->actingAsSession($this->admin)->postJson("/api/products/{$this->gold->id}/balance", ['amount' => 1]);
        }

        $response = $this->actingAsSession($this->admin)
            ->getJson('/api/history')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 14);

        $this->actingAsSession($this->admin)
            ->getJson('/api/history?page=2')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('current_page', 2);
    }

    public function test_rejects_invalid_jalali_date(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson('/api/history?from=نامعتبر')
            ->assertStatus(422);
    }

    public function test_product_history_filters_by_jalali_date_range(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->gold->id}/history?from=۱۴۰۵/۰۶/۰۱&to=۱۴۰۵/۰۶/۳۰")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.product_id', $this->gold->id);

        $this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->gold->id}/history?from=۱۴۰۵/۰۷/۰۱")
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->gold->id}/history?from=نامعتبر")
            ->assertStatus(422);
    }

    public function test_history_options_lists_involved_users_and_products(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson('/api/history/options')
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonCount(2, 'products');
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
