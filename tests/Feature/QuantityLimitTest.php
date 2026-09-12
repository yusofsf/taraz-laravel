<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QuantityLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
    }

    public function test_person_product_balance_accepts_multibillion_quantity(): void
    {
        $rial = Product::create(['name' => 'ریال', 'quantity' => 0, 'unit' => 'عدد']);
        $person = Person::create(['name' => 'علی']);

        $this->actingAsSession($this->admin)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $rial->id, 'quantity' => 2500000000],
                ],
            ])
            ->assertOk();

        $this->assertSame(2500000000.0, PersonProduct::first()->quantity);
    }

    public function test_product_initial_balance_accepts_multibillion_quantity(): void
    {
        // «ریال» از مایگریشن seed هم وجود دارد؛ برای همین با شناسه بررسی می‌کنیم
        $response = $this->actingAsSession($this->admin)
            ->postJson('/api/products', ['name' => 'سکه', 'quantity' => -3200000000, 'unit' => 'عدد'])
            ->assertCreated()
            ->assertJsonPath('quantity', -3200000000);

        $this->assertSame(-3200000000.0, Product::findOrFail($response->json('id'))->quantity);
    }

    public function test_balance_adjustment_accepts_multibillion_amount(): void
    {
        $rial = Product::create(['name' => 'ریال', 'quantity' => 0, 'unit' => 'عدد']);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$rial->id}/balance", ['amount' => 4100000000])
            ->assertCreated()
            ->assertJsonPath('new_quantity', 4100000000);

        $this->assertSame(4100000000.0, $rial->fresh()->quantity);
    }

    public function test_trade_quantity_accepts_multibillion_amount(): void
    {
        $coin = Product::create(['name' => 'سکه', 'quantity' => 0, 'unit' => 'عدد']);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$coin->id}/balance", [
                'direction' => 'خرید',
                'quantity' => 5000000000,
                'unit_price' => 1,
                'trade_date' => '1405/06/25',
                'settlement_method' => 'ریال',
                'settlement_date' => '1405/06/25',
            ])
            ->assertCreated()
            ->assertJsonPath('new_quantity', 5000000000);

        $this->assertSame(5000000000.0, $coin->fresh()->quantity);
    }

    public function test_quantities_beyond_the_cap_are_still_rejected(): void
    {
        $rial = Product::create(['name' => 'ریال', 'quantity' => 0, 'unit' => 'عدد']);

        $this->actingAsSession($this->admin)
            ->postJson('/api/products', ['name' => 'کاغذ', 'quantity' => Product::MAX_QUANTITY + 1, 'unit' => 'عدد'])
            ->assertStatus(422);

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$rial->id}/balance", ['amount' => Product::MAX_QUANTITY + 1])
            ->assertStatus(422);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
