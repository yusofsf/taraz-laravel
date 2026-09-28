<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TradesListTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
    }

    public function test_lists_only_buy_trades_for_buy_direction(): void
    {
        $this->recordTrade('خرید');
        $this->recordTrade('فروش');

        $this->actingAsSession($this->admin)
            ->getJson('/api/trades?direction=خرید')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'خرید')
            ->assertJsonPath('total', 1);
    }

    public function test_lists_only_sale_trades_for_sale_direction(): void
    {
        $this->recordTrade('خرید');
        $this->recordTrade('فروش');

        $this->actingAsSession($this->admin)
            ->getJson('/api/trades?direction=فروش')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'فروش')
            ->assertJsonPath('total', 1);
    }

    public function test_rejects_an_unknown_direction(): void
    {
        $this->actingAsSession($this->admin)
            ->getJson('/api/trades?direction=نامعتبر')
            ->assertStatus(422);
    }

    public function test_paginates_ten_trades_per_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->recordTrade('خرید');
        }

        $this->actingAsSession($this->admin)
            ->getJson('/api/trades?direction=خرید')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2);
    }

    public function test_filters_trades_by_product(): void
    {
        $coin = Product::create(['name' => 'سکه', 'quantity' => 0, 'unit' => 'عدد']);
        $this->recordTrade('خرید');
        $this->recordTrade('خرید', $coin);

        $this->actingAsSession($this->admin)
            ->getJson("/api/trades?direction=خرید&product_id={$this->gold->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $this->gold->id);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/trades?direction=خرید')->assertStatus(401);
    }

    private function recordTrade(string $direction, ?Product $product = null): BalanceChange
    {
        $product ??= $this->gold;

        $response = $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$product->id}/balance", [
                'direction' => $direction,
                'quantity' => 1,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
            ])
            ->assertCreated();

        return BalanceChange::findOrFail($response->json('id'));
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
