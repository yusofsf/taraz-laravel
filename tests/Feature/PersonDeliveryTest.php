<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PersonDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $gold;

    private Person $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 40, 'unit' => 'گرم']);
        $this->ali = Person::create(['name' => 'علی']);
    }

    public function test_delivery_reduces_the_person_warehouse_without_touching_stock(): void
    {
        // انبار علی ۱۲ گرم است
        $this->keep(12);

        $this->delivery(['quantity' => 5])->assertStatus(201);

        $this->assertSame(7.0, $this->warehouse($this->ali));
        // تراز انبار کالا تغییر نمی‌کند
        $this->assertSame(40.0, (float) $this->gold->fresh()->quantity);

        $record = BalanceChange::where('type', 'delivery')->first();
        $this->assertNotNull($record);
        $this->assertSame($this->ali->id, $record->person_id);
        $this->assertEquals(-5.0, (float) $record->change_amount);
        $this->assertFalse((bool) $record->record_in_balance);
        $this->assertSame('2026-08-23', $record->trade_date->format('Y-m-d'));
        $this->assertSame('تحویل آزمایشی', $record->note);
    }

    public function test_delivery_can_empty_the_warehouse_and_go_negative(): void
    {
        $this->keep(3);

        $this->delivery(['quantity' => 5])->assertStatus(201);

        $this->assertSame(-2.0, $this->warehouse($this->ali));
    }

    public function test_delivery_is_rejected_with_bad_input(): void
    {
        $this->delivery(['quantity' => 0])->assertStatus(422);
        $this->delivery(['trade_date' => null])->assertStatus(422);
        $this->delivery(['product_id' => 999999])->assertStatus(422);
        $this->delivery(['quantity' => -4])->assertStatus(422);

        $this->assertSame(0, BalanceChange::where('type', 'delivery')->count());
    }

    public function test_deleting_delivery_restores_the_warehouse(): void
    {
        $this->keep(12);
        $this->delivery(['quantity' => 5])->assertStatus(201);

        $record = BalanceChange::where('type', 'delivery')->first();

        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$record->id)
            ->assertStatus(204);

        $this->assertSame(12.0, $this->warehouse($this->ali));
        $this->assertSame(40.0, (float) $this->gold->fresh()->quantity);
    }

    public function test_editing_delivery_amount_syncs_the_warehouse(): void
    {
        $this->keep(12);
        $this->delivery(['quantity' => 5])->assertStatus(201);

        $record = BalanceChange::where('type', 'delivery')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$record->id, [
                'amount' => -8,
                'person_id' => $this->ali->id,
                'trade_date' => '1405/06/05',
            ])
            ->assertStatus(200);

        $this->assertSame(4.0, $this->warehouse($this->ali));
        $this->assertEquals(-8.0, (float) $record->fresh()->change_amount);
        $this->assertSame(40.0, (float) $this->gold->fresh()->quantity);
    }

    public function test_delivery_appears_in_history_and_logs_activity(): void
    {
        $this->keep(12);
        $this->delivery()->assertStatus(201);

        $items = $this->actingAsSession($this->admin)->getJson('/api/history?all=1')->assertOk()->json();
        $delivery = collect($items)->firstWhere('type', 'delivery');

        $this->assertNotNull($delivery);
        $this->assertSame($this->ali->id, $delivery['person']['id']);

        $this->assertSame(1, ActivityLog::where('action', 'delivery')->count());

        // تحویل در فهرست معاملات آتی و فاکتورهای امروز نمی‌آید
        $this->actingAsSession($this->admin)->getJson('/api/trades/future')->assertOk()->assertJsonCount(0);
        $this->actingAsSession($this->admin)->getJson('/api/invoices/today')->assertOk()->assertJsonPath('stats.count', 0);
    }

    public function test_delivery_works_for_money_products(): void
    {
        $rial = Product::firstOrCreate(['name' => 'ریال'], ['sku' => 'RIAL', 'quantity' => 0, 'unit' => 'عدد']);

        $this->actingAsSession($this->admin)
            ->postJson('/api/persons/'.$this->ali->id.'/delivery', [
                'product_id' => $rial->id,
                'quantity' => 1000,
                'trade_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $this->assertSame(-1000.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(0.0, (float) $rial->fresh()->quantity);
    }

    private function delivery(array $overrides = [])
    {
        return $this->actingAsSession($this->admin)->postJson('/api/persons/'.$this->ali->id.'/delivery', array_merge([
            'product_id' => $this->gold->id,
            'quantity' => 5,
            'trade_date' => '1405/06/01',
            'note' => 'تحویل آزمایشی',
        ], $overrides));
    }

    /** موجودی انبار شخص را مستقیم می‌سازد تا اثر تحویل سنجیده شود */
    private function keep(float $quantity): void
    {
        PersonProduct::create([
            'person_id' => $this->ali->id,
            'product_id' => $this->gold->id,
            'quantity' => $quantity,
        ]);
    }

    private function warehouse(Person $person): float
    {
        return (float) (PersonProduct::where('person_id', $person->id)
            ->where('product_id', $this->gold->id)
            ->value('quantity') ?? 0);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
