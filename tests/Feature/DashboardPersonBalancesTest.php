<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardPersonBalancesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $gold;

    private Product $coin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'mobile' => '09111111111',
        ]);
        $this->gold = Product::create([
            'name' => 'طلا', 'sku' => 'AU', 'quantity' => 100, 'unit' => 'گرم',
        ]);
        $this->coin = Product::create([
            'name' => 'سکه', 'sku' => 'COIN', 'quantity' => 20, 'unit' => 'عدد',
        ]);
    }

    public function test_summarizes_debtor_and_creditor_totals_per_product(): void
    {
        $first = Person::factory()->create(['name' => 'آمنه']);
        $second = Person::factory()->create(['name' => 'زهرا']);
        $third = Person::factory()->create(['name' => 'مینا']);

        PersonProduct::create(['person_id' => $first->id, 'product_id' => $this->gold->id, 'quantity' => 12.5]);
        PersonProduct::create(['person_id' => $second->id, 'product_id' => $this->gold->id, 'quantity' => 7.5]);
        PersonProduct::create(['person_id' => $third->id, 'product_id' => $this->gold->id, 'quantity' => -4]);
        PersonProduct::create(['person_id' => $first->id, 'product_id' => $this->coin->id, 'quantity' => -3]);
        PersonProduct::create(['person_id' => $second->id, 'product_id' => $this->coin->id, 'quantity' => 5]);

        $rows = collect($this->actingAsSession($this->admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json('person_balances'));

        // ترتیب بر اساس نام کالا است؛ SQLite بایت‌به‌بایت مقایسه می‌کند، پس «سکه» پیش از «طلا» می‌آید
        $this->assertSame(['سکه', 'طلا'], $rows->pluck('name')->all());

        $gold = $rows->firstWhere('name', 'طلا');
        $this->assertEquals(20.0, $gold['debtor']);
        $this->assertEquals(4.0, $gold['creditor']);
        $this->assertSame('گرم', $gold['unit']);
        $this->assertSame($this->gold->id, $gold['id']);

        $coin = $rows->firstWhere('name', 'سکه');
        $this->assertEquals(5.0, $coin['debtor']);
        $this->assertEquals(3.0, $coin['creditor']);
        $this->assertSame('عدد', $coin['unit']);
        $this->assertSame($this->coin->id, $coin['id']);
    }

    public function test_skips_products_without_any_balance(): void
    {
        // کالای بدون شخص و کالایی که همه مقدارهایش صفر است نباید در پاسخ بیایند
        Product::create(['name' => 'نقره', 'sku' => 'AG', 'quantity' => 5, 'unit' => 'گرم']);
        $settled = Person::factory()->create(['name' => 'تسویه‌شده']);
        PersonProduct::create(['person_id' => $settled->id, 'product_id' => $this->gold->id, 'quantity' => 0]);

        $this->assertSame([], $this->actingAsSession($this->admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json('person_balances'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
