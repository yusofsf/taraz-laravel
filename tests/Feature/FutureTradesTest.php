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

class FutureTradesTest extends TestCase
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
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 5, 'unit' => 'گرم']);
        $this->ali = Person::create(['name' => 'علی']);
        $this->reza = Person::create(['name' => 'رضا']);
    }

    private function recordFutureTrade(array $overrides = []): BalanceChange
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', array_merge([
                'direction' => 'خرید',
                'record_in_balance' => false,
                'quantity' => 2,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
                'person_id' => $this->ali->id,
            ], $overrides))
            ->assertStatus(201);

        return BalanceChange::where('type', 'trade')->first();
    }

    public function test_future_trades_endpoint_lists_only_trades_without_record_in_balance(): void
    {
        $this->recordFutureTrade();

        // معامله معمولی و تعدیل نباید در فهرست معامله‌های آتی بیایند
        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', [
                'direction' => 'فروش',
                'quantity' => 1,
                'unit_price' => 1000,
                'trade_date' => '1405/06/01',
                'settlement_method' => 'کاغذ',
                'settlement_date' => '1405/06/01',
            ])
            ->assertStatus(201);

        $this->actingAsSession($this->admin)
            ->postJson('/api/products/'.$this->gold->id.'/balance', ['amount' => 3])
            ->assertStatus(201);

        $response = $this->actingAsSession($this->admin)
            ->getJson('/api/trades/future')
            ->assertOk();

        $trades = $response->json();
        $this->assertCount(1, $trades);
        $this->assertFalse((bool) $trades[0]['record_in_balance']);
        $this->assertSame($this->ali->id, $trades[0]['person']['id']);
        $this->assertSame('طلا', $trades[0]['product']['name']);
    }

    public function test_future_trades_endpoint_filters_by_effective_date(): void
    {
        $this->recordFutureTrade(['trade_date' => '1405/05/10']);
        $this->recordFutureTrade(['trade_date' => '1405/06/20']);

        // تاریخ معامله ملاک است، نه زمان ثبت رکورد
        $this->actingAsSession($this->admin)
            ->getJson('/api/trades/future?from=1405/06/01&to=1405/06/30')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.trade_date_jalali', '1405/06/20');

        $this->actingAsSession($this->admin)
            ->getJson('/api/trades/future?from=1405/05/01&to=1405/05/31')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.trade_date_jalali', '1405/05/10');

        $this->actingAsSession($this->admin)
            ->getJson('/api/trades/future?from=1405/07/01')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_future_havale_trade_does_not_move_person_money_balances(): void
    {
        $this->recordFutureTrade([
            'settlement_method' => 'حواله',
            'settlement_medium' => 'ریال',
            'from_person_id' => $this->ali->id,
            'to_person_id' => $this->reza->id,
            'person_id' => null,
        ]);

        $rial = Product::where('name', 'ریال')->first();

        // معامله آتی هنوز تسویه ندارد؛ حواله جابه‌جا نمی‌شود و ریال هم تغییر نمی‌کند
        $this->assertSame(0.0, (float) $rial->quantity);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity') ?? 0);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity') ?? 0);
        $this->assertSame(0, BalanceChange::where('type', 'settlement')->count());

        // وقتی معامله به تراز می‌رود، حواله هم اعمال می‌شود
        $trade = BalanceChange::where('type', 'trade')->first();
        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 2, 'record_in_balance' => true])
            ->assertStatus(200);

        $this->assertSame(-2000.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(2000.0, (float) PersonProduct::where('person_id', $this->reza->id)->where('product_id', $rial->id)->value('quantity'));
        $this->assertSame(1, BalanceChange::where('type', 'settlement')->count());
    }

    public function test_editing_future_trade_amount_syncs_person_balance(): void
    {
        $this->recordFutureTrade();

        $trade = BalanceChange::where('type', 'trade')->first();

        $this->actingAsSession($this->admin)
            ->putJson('/api/history/'.$trade->id, ['amount' => 3, 'record_in_balance' => false, 'person_id' => $this->ali->id])
            ->assertStatus(200);

        // موجودی کالا دست‌نخورده می‌ماند اما بدهکاری شخص هم‌گام مقدار تازه می‌شود
        $this->assertSame(5.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(3.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));
    }

    public function test_future_trade_sale_makes_counterparty_creditor(): void
    {
        $this->recordFutureTrade([
            'direction' => 'فروش',
            'quantity' => 2,
        ]);

        // فروش آتی: کالا هنوز تحویل نشده؛ شخص طلبکار دیده می‌شود
        $this->assertSame(5.0, (float) $this->gold->fresh()->quantity);
        $this->assertSame(-2.0, (float) PersonProduct::where('person_id', $this->ali->id)->where('product_id', $this->gold->id)->value('quantity'));

        $response = $this->actingAsSession($this->admin)
            ->getJson('/api/products/'.$this->gold->id.'/persons')
            ->assertOk();

        $ali = collect($response->json('persons'))->firstWhere('id', $this->ali->id);
        $this->assertSame('طلبکار', $ali['status']);
        $this->assertEquals(-2.0, $ali['quantity']);
        $this->assertEquals(2.0, $response->json('totals.creditor'));
    }

    public function test_future_trades_do_not_appear_in_today_invoices(): void
    {
        $todayJalali = Jalali::format(today(), false);
        $this->recordFutureTrade(['trade_date' => $todayJalali]);

        $this->actingAsSession($this->admin)
            ->getJson('/api/invoices/today')
            ->assertOk()
            ->assertJsonPath('stats.count', 0);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
