<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HistoryProductEditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $editor;

    private Product $gold;

    private Product $coin;

    private Person $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->editor = User::factory()->create(['mobile' => '09222222222', 'can_edit_history' => true]);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        $this->coin = Product::create(['name' => 'سکه', 'quantity' => 0, 'unit' => 'عدد']);
        $this->ali = Person::create(['name' => 'علی']);
    }

    public function test_edit_moves_a_trade_to_another_product(): void
    {
        $trade = $this->recordGoldTrade(10, $this->ali->id);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$trade->id}", [
                'amount' => 10,
                'unit_price' => (float) $trade->unit_price,
                'person_id' => $this->ali->id,
                'product_id' => $this->coin->id,
            ])
            ->assertOk()
            ->assertJsonPath('product_id', $this->coin->id);

        $this->assertSame(0.0, (float) $this->gold->refresh()->quantity);
        $this->assertSame(10.0, (float) $this->coin->refresh()->quantity);

        // سهم شخص از کالای قبلی برداشته و روی کالای جدید نشسته است
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));
        $this->assertSame(10.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->coin->id)->value('quantity'));

        // رکورد تسویه به کالای معامله وابسته نیست و روی کالای پول میماند
        $settlement = BalanceChange::where('parent_id', $trade->id)->where('type', 'settlement')->first();
        $this->assertNotNull($settlement);
        $this->assertNotSame($this->coin->id, $settlement->product_id);
    }

    public function test_edit_rejects_an_invalid_unit_for_the_new_product(): void
    {
        $trade = $this->recordGoldTrade(10);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$trade->id}", [
                'amount' => 10.5,
                'unit_price' => (float) $trade->unit_price,
                'product_id' => $this->coin->id,
            ])
            ->assertStatus(422);

        $this->assertSame(10.0, (float) $this->gold->refresh()->quantity);
        $this->assertSame(0.0, (float) $this->coin->refresh()->quantity);
    }

    public function test_edit_rejects_an_unknown_product(): void
    {
        $trade = $this->recordGoldTrade(10);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$trade->id}", ['amount' => 10, 'product_id' => 999])
            ->assertStatus(422);
    }

    public function test_chain_is_rebuilt_for_both_products(): void
    {
        $first = $this->recordGoldTrade(10);
        $second = $this->recordGoldTrade(5);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$first->id}", ['amount' => 10, 'product_id' => $this->coin->id])
            ->assertOk();

        // زنجیرهٔ کالای قبلی از صفر شروع میشود
        $second->refresh();
        $this->assertSame(0.0, (float) $second->previous_quantity);
        $this->assertSame(5.0, (float) $second->new_quantity);

        // زنجیرهٔ کالای جدید فقط همین رکورد را دارد
        $moved = BalanceChange::findOrFail($first->id);
        $this->assertSame($this->coin->id, $moved->product_id);
        $this->assertSame(0.0, (float) $moved->previous_quantity);
        $this->assertSame(10.0, (float) $moved->new_quantity);
    }

    private function recordGoldTrade(float $quantity, ?int $personId = null): BalanceChange
    {
        $response = $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->gold->id}/balance", [
                'direction' => 'خرید',
                'quantity' => $quantity,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'person_id' => $personId,
            ])
            ->assertCreated();

        return BalanceChange::findOrFail($response->json('id'));
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
