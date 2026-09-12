<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Jalali;
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
                'trade_date' => '1405/06/01',
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
                'trade_date' => '1405/06/01',
                'settlement_method' => 'ریال',
                'settlement_date' => '1405/06/01',
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
                'trade_date' => '1405/06/01',
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'settlement_date' => '1405/06/01',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        $rial = Product::where('name', 'ریال')->first();
        $trade = BalanceChange::where('type', 'trade')->first();

        $this->assertSame(3.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) $rial->quantity);
        $this->assertSame($this->ali->id, $trade->from_person_id);
        $this->assertSame($this->reza->id, $trade->to_person_id);
        $this->assertSame(-1500.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1500.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
    }

    public function test_deleting_havale_trade_rolls_back_person_balances(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 500,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'settlement_date' => '1405/06/01',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();
        $rial = Product::where('name', 'ریال')->first();

        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$trade->id)
            ->assertStatus(204);

        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) $rial->fresh()->quantity);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(0, BalanceChange::count());
    }

    public function test_deleting_legacy_havale_trade_without_persons_on_trade_row_still_rolls_back(): void
    {
        // رکوردهای قدیمی طرف‌های حواله را فقط روی رکورد تسویه دارند، نه روی خود معامله
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 500,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'settlement_date' => '1405/06/01',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        BalanceChange::where('type', 'trade')->update(['from_person_id' => null, 'to_person_id' => null]);

        $trade = BalanceChange::where('type', 'trade')->first();
        $rial = Product::where('name', 'ریال')->first();

        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$trade->id)
            ->assertStatus(204);

        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
    }

    public function test_editing_havale_trade_amount_syncs_person_balances(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 500,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'settlement_date' => '1405/06/01',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();
        $rial = Product::where('name', 'ریال')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 3])
            ->assertStatus(200);

        // 2×500=1000 قبلاً جابه‌جا شده بود؛ حالا باید 3×500=1500 باشد
        $this->assertSame(-1500.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1500.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1500.0, (float) $trade->fresh()->total_price);
    }

    public function test_havale_requires_two_different_persons(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'trade_date' => '1405/06/01',
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
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
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
        // فاکتورهای امروز بر اساس تاریخ معامله فیلتر می‌شوند، نه زمان ثبت
        $todayJalali = Jalali::format(today(), false);
        foreach ([['خرید', 2, 1000], ['فروش', 1, 3000]] as [$direction, $quantity, $price]) {
            $this->actingAsSession($this->admin)
                ->postJson('/api/products/'.$this->gold->id.'/balance', [
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'trade_date' => $todayJalali,
                    'settlement_method' => 'کاغذ',
                    'settlement_date' => '1405/06/01',
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

    public function test_trade_without_record_in_balance_does_not_touch_balances(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'record_in_balance' => false,
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) Product::where('name', 'کاغذ')->value('quantity'));
        $this->assertFalse((bool) PersonProduct::where('person_id', $this->ali->id)->count());
        $this->assertFalse((bool) $trade->record_in_balance);
        $this->assertEquals(2.0, (float) $trade->change_amount);
        $this->assertSame(0, BalanceChange::where('type', 'settlement')->count());

        // حذفش هم تراز را تغییر نمی‌دهد
        $this->actingAsSession($this->admin)
            ->deleteJson('/api/history/'.$trade->id)
            ->assertStatus(204);

        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
    }

    public function test_settlement_date_is_required(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settlement_date');
    }

    public function test_trade_date_is_required_and_stored(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('trade_date');

        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'trade_date' => '1405/06/05',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/10',
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();
        $this->assertSame('2026-08-27', $trade->trade_date->format('Y-m-d'));
        $this->assertSame('2026-09-01', $trade->settlement_date->format('Y-m-d'));
    }

    public function test_history_filters_by_user_entered_trade_date(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 1,
                'unit_price' => 100,
                'trade_date' => '1405/06/05',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/05',
            ])
            ->assertStatus(201);

        // معامله با تاریخ ورودی کاربر، نه زمان ثبت رکورد؛ رکورد تسویه‌ی فرعی هم
        // چون trade_date ندارد با تاریخ ایجاد (امروز) حساب می‌شود
        $this->actingAsSession($this->admin)
            ->getJson('/api/history?from=1405/06/01&to=1405/06/30&all=1')
            ->assertOk()
            ->assertJsonCount(2);

        // بازه‌ای که تاریخ ایجادِ رکوردها هم داخلش نیست
        $this->actingAsSession($this->admin)
            ->getJson('/api/history?from=1405/05/01&to=1405/05/31&all=1')
            ->assertOk()
            ->assertJsonCount(0);

        // فاکتورهای امروز هم بر اساس تاریخ معامله کار می‌کنند
        $this->actingAsSession($this->admin)
            ->getJson('/api/invoices/today')
            ->assertOk()
            ->assertJsonPath('stats.count', 0);
    }

    public function test_toggling_record_in_balance_applies_and_removes_balance_effects(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'record_in_balance' => false,
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        // تیک را می‌گذاریم: تراز کالا، شخص و تسویه باید اعمال شود
        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, [
                'amount' => 2,
                'record_in_balance' => true,
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(200);

        $trade = $trade->fresh();
        $paper = Product::where('name', 'کاغذ')->first();

        $this->assertTrue((bool) $trade->record_in_balance);
        $this->assertSame(2.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(-2000.0, (float) $paper->quantity);
        $this->assertSame(2.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));
        $this->assertSame(1, BalanceChange::where('type', 'settlement')->count());

        // تیک را برمی‌داریم: همه اثرها باید برگردد
        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, [
                'amount' => 2,
                'record_in_balance' => false,
                'person_id' => $this->ali->id,
            ])
            ->assertStatus(200);

        $trade = $trade->fresh();

        $this->assertFalse((bool) $trade->record_in_balance);
        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) $paper->fresh()->quantity);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));
        $this->assertSame(0, BalanceChange::where('type', 'settlement')->count());
    }

    public function test_editing_trade_unit_price_syncs_settlement(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 2, 'unit_price' => 1500])
            ->assertStatus(200);

        // 2×1000 قبلاً از کاغذ کم شده بود؛ حالا باید 2×1500 باشد
        $this->assertSame(-3000.0, (float) Product::where('name', 'کاغذ')->value('quantity'));
        $this->assertSame(1500.0, (float) $trade->fresh()->unit_price);
        $this->assertSame(3000.0, (float) $trade->fresh()->total_price);
        $this->assertSame(3000.0, (float) BalanceChange::where('type', 'settlement')->value('total_price'));
    }

    public function test_editing_trade_unit_price_and_amount_together_sync_settlement(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'فروش',
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'ریال',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => -3, 'unit_price' => 2000])
            ->assertStatus(200);

        // فروش ۳ گرم با قیمت ۲۰۰۰: ۶۰۰۰ ریال باید اضافه شده باشد (قبلاً ۲۰۰۰ بود)
        $this->assertSame(6000.0, (float) Product::where('name', 'ریال')->value('quantity'));
        $this->assertSame(6000.0, (float) $trade->fresh()->total_price);
    }

    public function test_editing_havale_trade_unit_price_syncs_person_balances(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 500,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'حواله',
                'settlement_medium' => 'ریال',
                'settlement_date' => '1405/06/01',
                'from_person_id' => $this->ali->id,
                'to_person_id' => $this->reza->id,
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();
        $rial = Product::where('name', 'ریال')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 2, 'unit_price' => 700])
            ->assertStatus(200);

        // 2×500=1000 قبلاً جابه‌جا شده بود؛ حالا باید 2×700=1400 باشد
        $this->assertSame(-1400.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1400.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1400.0, (float) $trade->fresh()->total_price);
    }

    public function test_editing_unit_price_of_trade_outside_balance_updates_total_only(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'record_in_balance' => false,
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 2, 'unit_price' => 2500, 'record_in_balance' => false])
            ->assertStatus(200);

        // خارج از تراز: هیچ ترازی تغییر نمی‌کند؛ فقط قیمت کل رکورد به‌روز می‌شود
        $this->assertSame(0.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(0.0, (float) Product::where('name', 'کاغذ')->value('quantity'));
        $this->assertSame(5000.0, (float) $trade->fresh()->total_price);
        $this->assertSame(0, BalanceChange::where('type', 'settlement')->count());
    }

    public function test_editing_adjust_record_ignores_unit_price(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', ['amount' => 10])
            ->assertStatus(201);

        $adjust = BalanceChange::where('type', 'adjust')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$adjust->id, ['amount' => 12, 'unit_price' => 999])
            ->assertStatus(200);

        // تعدیل قیمت واحد ندارد؛ مقدار ارسالی نادیده گرفته می‌شود
        $this->assertSame(0.0, (float) $adjust->fresh()->unit_price);
        $this->assertSame(12.0, (float) $this->gold->fresh()->quantity);
    }

    public function test_unit_price_must_be_positive(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'خرید',
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 2, 'unit_price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_price');
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
