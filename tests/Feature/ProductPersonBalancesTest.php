<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductPersonBalancesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'mobile' => '09111111111',
        ]);
        $this->product = Product::create([
            'name' => 'طلا', 'sku' => 'AU', 'quantity' => 100, 'unit' => 'گرم',
        ]);
    }

    public function test_lists_person_balance_status_per_product(): void
    {
        $debtor = Person::factory()->create(['name' => 'بدهکار']);
        $creditor = Person::factory()->create(['name' => 'طلبکار']);
        $settled = Person::factory()->create(['name' => 'تسویه‌شده']);
        $fresh = Person::factory()->create(['name' => 'بدون سابقه']);

        PersonProduct::create(['person_id' => $debtor->id, 'product_id' => $this->product->id, 'quantity' => 12.5]);
        PersonProduct::create(['person_id' => $creditor->id, 'product_id' => $this->product->id, 'quantity' => -4]);
        PersonProduct::create(['person_id' => $settled->id, 'product_id' => $this->product->id, 'quantity' => 0]);

        $response = $this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->product->id}/persons")
            ->assertOk();

        $this->assertSame($this->product->id, $response->json('product.id'));
        $this->assertSame('طلا', $response->json('product.name'));

        $this->assertEquals(12.5, $response->json('totals.debtor'));
        $this->assertEquals(4.0, $response->json('totals.creditor'));
        $this->assertEquals(-8.5, $response->json('totals.net'));

        $persons = collect($response->json('persons'));
        $this->assertSame(4, $persons->count());

        $this->assertSame(12.5, $persons->firstWhere('name', 'بدهکار')['quantity']);
        $this->assertSame('بدهکار', $persons->firstWhere('name', 'بدهکار')['status']);
        $this->assertEquals(-4.0, $persons->firstWhere('name', 'طلبکار')['quantity']);
        $this->assertSame('طلبکار', $persons->firstWhere('name', 'طلبکار')['status']);
        $this->assertSame('تسویه', $persons->firstWhere('name', 'تسویه‌شده')['status']);
        $this->assertSame(0, $persons->firstWhere('name', 'بدون سابقه')['quantity']);
        $this->assertSame('تسویه', $persons->firstWhere('name', 'بدون سابقه')['status']);
    }

    public function test_totals_are_zero_without_any_person_balance(): void
    {
        Person::factory()->create(['name' => 'بدون سابقه']);

        $response = $this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->product->id}/persons")
            ->assertOk();

        $this->assertEquals(0.0, $response->json('totals.debtor'));
        $this->assertEquals(0.0, $response->json('totals.creditor'));
        $this->assertEquals(0.0, $response->json('totals.net'));
    }

    public function test_persons_are_ordered_by_name(): void
    {
        Person::factory()->create(['name' => 'زهرا']);
        Person::factory()->create(['name' => 'آمنه']);

        $names = collect($this->actingAsSession($this->admin)
            ->getJson("/api/products/{$this->product->id}/persons")
            ->assertOk()
            ->json('persons'))
            ->pluck('name');

        $this->assertSame(['آمنه', 'زهرا'], $names->all());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/products/{$this->product->id}/persons")
            ->assertUnauthorized();
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
