<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TradeSettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $gold;

    private Person $ali;

    private Person $reza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        $this->ali = Person::create(['name' => 'علی']);
        $this->reza = Person::create(['name' => 'رضا']);
    }

    public function test_buy_with_paper_settlement_decreases_paper_product(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 1000,
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(201);

        $this->gold->refresh();
        $paper = Product::where('name', 'کاغذ')->first();

        $this->assertSame(2.0, (float) $this->gold->quantity);
        $this->assertNotNull($paper);
        $this->assertSame(-2000.0, (float) $paper->quantity);
        $this->assertSame(2.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));

        $settlement = BalanceChange::where('type', 'settlement')->first();
        $this->assertNotNull($settlement);
        $this->assertEquals(-2000.0, (float) $settlement->change_amount);
        $this->assertSame('2026-08-23', $settlement->settlement_date->format('Y-m-d'));
    }

    public function test_sale_with_rial_settlement_increases_rial_product(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'فروش',
                'quantity' => 1.5,
                'unit_price' => 2000,
                'settlement_method' => 'ریال',
            ])
            ->assertStatus(201);

        $rial = Product::where('name', 'ریال')->first();
        $this->assertSame(-1.5, (float) $this->gold->fresh()->quantity);
        $this->assertSame(3000.0, (float) $rial->quantity);
    }

    public function test_havale_settlement_moves_value_between_persons(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 3,
                'unit_price' => 500,
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        $rial = Product::where('name', 'ریال')->first();

        $this->assertSame(3.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) $rial->quantity);
        $this->assertSame(-1500.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1500.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
    }

    public function test_havale_requires_two_different_persons(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'settlement_method' => 'حواله',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->ali->id,
            ])
            ->assertStatus(422);
    }

    public function test_deleting_trade_rolls_back_settlement(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 1000,
                'settlement_method' => 'کاغذ',
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$trade->id)
            ->assertStatus(204);

        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) Product::where('name', 'کاغذ')->value('quantity'));
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));
        $this->assertSame(0, BalanceChange::count());
    }

    public function test_today_invoices_reports_averages(): void
    {
        foreach ([['خرید', 2, 1000], ['فروش', 1, 3000]] as [$direction, $quantity, $price]) {
            $this->actingAsSession($this->admin)
                ->postJson('/api/products/'.$this->gold->id.'/balance', [
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'settlement_method' => 'کاغذ',
                ])
                ->assertStatus(201);
        }

        $response = $this->actingAsSession($this->admin)->getJson('/api/invoices/today');

        $response->assertStatus(200);
        $stats = $response->json('stats');

        $this->assertSame(2, $stats['count']);
        $this->assertEquals(2000.0, $stats['buy_value']);
        $this->assertEquals(3000.0, $stats['sale_value']);
        $this->assertEquals(1000.0, $stats['avg_buy_price']);
        $this->assertEquals(3000.0, $stats['avg_sale_price']);
        $this->assertEquals(2.0, $stats['avg_buy_weight']);
        $this->assertEquals(1.0, $stats['avg_sale_weight']);
        $this->assertEquals(1.0, $stats['balance']);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
