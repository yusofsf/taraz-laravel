<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GoodsTransferTest extends TestCase
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
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 100, 'unit' => 'گرم']);
        $this->ali = Person::create(['name' => 'علی']);
        $this->reza = Person::create(['name' => 'رضا']);
    }

    public function test_transfer_moves_goods_between_two_persons_without_touching_stock(): void
    {
        $this->transfer()->assertStatus(201);

        $this->assertSame(-10.0, $this->balance($this->ali));
        $this->assertSame(10.0, $this->balance($this->reza));
        // موجودی انبار کالا دست‌نخورده می‌ماند
        $this->assertSame(100.0, (float) $this->gold->fresh()->quantity);

        $record = BalanceChange::where('type', 'transfer')->first();
        $this->assertNotNull($record);
        $this->assertSame($this->ali->id, $record->from_person_id);
        $this->assertSame($this->reza->id, $record->to_person_id);
        $this->assertEquals(10.0, (float) $record->change_amount);
        $this->assertFalse((bool) $record->record_in_balance);
        $this->assertSame('2026-08-23', $record->trade_date->format('Y-m-d'));
        $this->assertSame('حواله آزمایشی', $record->note);
    }

    public function test_transfer_works_for_money_products_too(): void
    {
        foreach (['ریال', 'کاغذ'] as $name) {
            $money = Product::firstOrCreate(['name' => $name], ['sku' => $name, 'quantity' => 0, 'unit' => 'عدد']);

            $this->actingAsSession($this->admin)
                ->postJson('/api/products/'.$money->id.'/transfer', [
                    'quantity' => 5,
                    'from_person_id' => $this->ali->id,
                    'to_person_id' => $this->reza->id,
                    'trade_date' => '1405/06/01',
                ])
                ->assertStatus(201);

            // ریال و کاغذ هم مثل بقیه‌ی کالاها بین دو شخص جابه‌جا می‌شوند و
            // تراز انبارشان دست‌نخورده می‌ماند
            $this->assertSame(-5.0, $this->balance($this->ali, $money));
            $this->assertSame(5.0, $this->balance($this->reza, $money));
            $this->assertSame(0.0, (float) $money->fresh()->quantity);
        }

        $this->assertSame(2, BalanceChange::where('type', 'transfer')->count());
    }

    public function test_transfer_requires_two_different_persons(): void
    {
        $this->transfer(['to_person_id' => $this->ali->id])->assertStatus(422);
        $this->transfer(['from_person_id' => null])->assertStatus(422);
        $this->transfer(['to_person_id' => null])->assertStatus(422);
    }

    public function test_transfer_rejects_zero_quantity_and_missing_date(): void
    {
        $this->transfer(['quantity' => 0])->assertStatus(422);
        $this->transfer(['trade_date' => null])->assertStatus(422);
    }

    public function test_transfer_does_not_touch_stock_chain_or_trade_reports(): void
    {
        $this->transfer()->assertStatus(201);

        // حواله نه در فهرست معاملات آتی می‌آید و نه در فاکتورهای امروز
        $this->actingAsSession($this->admin)->getJson('/api/trades/future')->assertOk()->assertJsonCount(0);
        $this->actingAsSession($this->admin)->getJson('/api/invoices/today')->assertOk()->assertJsonPath('stats.count', 0);

        // و زنجیره‌ی تراز انبار را تغییر نمی‌دهد
        $record = BalanceChange::where('type', 'transfer')->first();
        $this->assertEquals(100.0, (float) $record->previous_quantity);
        $this->assertEquals(100.0, (float) $record->new_quantity);
    }

    public function test_transfer_appears_in_history_with_both_persons(): void
    {
        $this->transfer()->assertStatus(201);

        $items = $this->actingAsSession($this->admin)->getJson('/api/history?all=1')->assertOk()->json();

        $this->assertCount(1, $items);
        $this->assertSame('transfer', $items[0]['type']);
        $this->assertSame($this->ali->id, $items[0]['from_person']['id']);
        $this->assertSame($this->reza->id, $items[0]['to_person']['id']);
    }

    public function test_deleting_transfer_rolls_back_person_balances(): void
    {
        $this->transfer()->assertStatus(201);
        $record = BalanceChange::where('type', 'transfer')->first();

        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$record->id)
            ->assertStatus(204);

        $this->assertSame(0.0, $this->balance($this->ali));
        $this->assertSame(0.0, $this->balance($this->reza));
        $this->assertSame(100.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0, BalanceChange::count());
    }

    public function test_editing_transfer_syncs_person_balances(): void
    {
        $this->transfer()->assertStatus(201);
        $record = BalanceChange::where('type', 'transfer')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$record->id, [
                'quantity' => 15,
                'from_person_id' => $this->reza->id,
                'to_person_id' => $this->ali->id,
                'trade_date' => '1405/06/05',
                'note' => 'اصلاح حواله',
            ])
            ->assertStatus(200);

        // جهت حواله برگشته و مقدار هم ۱۵ شده است
        $this->assertSame(15.0, $this->balance($this->ali));
        $this->assertSame(-15.0, $this->balance($this->reza));
        $this->assertSame(100.0, (float) $this->gold->fresh()->quantity);

        $record = $record->fresh();
        $this->assertEquals(15.0, (float) $record->change_amount);
        $this->assertSame('2026-08-27', $record->trade_date->format('Y-m-d'));
        $this->assertSame('اصلاح حواله', $record->note);
    }

    public function test_editing_transfer_rejects_same_person_and_keeps_balances(): void
    {
        $this->transfer()->assertStatus(201);
        $record = BalanceChange::where('type', 'transfer')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$record->id, [
                'quantity' => 5,
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->ali->id,
            ])
            ->assertStatus(422);

        // درخواست نامعتبر نباید موجودی‌ها را جابه‌جا کند
        $this->assertSame(-10.0, $this->balance($this->ali));
        $this->assertSame(10.0, $this->balance($this->reza));
    }

    public function test_transfer_logs_activity(): void
    {
        $this->transfer()->assertStatus(201);

        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'transfer')->count());
    }

    private function transfer(array $overrides = [])
    {
        return $this->actingAsSession($this->admin)->postJson('/api/products/'.$this->gold->id.'/transfer', array_merge([
            'quantity' => 10,
            'from_person_id' => $this->ali->id,
            'to_person_id' => $this->reza->id,
            'trade_date' => '1405/06/01',
            'note' => 'حواله آزمایشی',
        ], $overrides));
    }

    private function balance(Person $person, ?Product $product = null): float
    {
        return (float) (PersonProduct::where('person_id', $person->id)
            ->where('product_id', ($product ?? $this->gold)->id)
            ->value('quantity') ?? 0);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
