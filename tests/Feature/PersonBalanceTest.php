<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PersonBalanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Product $product;

    private Person $person;

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
        $this->person = Person::factory()->create();
    }

    public function test_balance_change_with_person_updates_person_product_balance(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", [
                'amount' => -25,
                'person_id' => $this->person->id,
                'note' => 'فروش',
            ])
            ->assertCreated()
            ->assertJsonPath('person_id', $this->person->id)
            ->assertJsonPath('new_quantity', 75);

        $this->assertDatabaseHas('person_product', [
            'person_id' => $this->person->id,
            'product_id' => $this->product->id,
            'quantity' => -25,
        ]);

        $this->assertDatabaseHas('balance_changes', [
            'product_id' => $this->product->id,
            'person_id' => $this->person->id,
            'change_amount' => -25,
        ]);
    }

    public function test_second_change_accumulates_person_balance(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", [
                'amount' => 10, 'person_id' => $this->person->id,
            ])
            ->assertCreated();

        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", [
                'amount' => 5, 'person_id' => $this->person->id,
            ])
            ->assertCreated();

        $this->assertSame(15.0, PersonProduct::first()->quantity);
        $this->assertSame(115.0, $this->product->fresh()->quantity);
    }

    public function test_balance_change_without_person_keeps_product_only(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", ['amount' => 7])
            ->assertCreated()
            ->assertJsonPath('person_id', null);

        $this->assertSame(0, PersonProduct::count());
    }

    public function test_user_without_permission_cannot_change_balance(): void
    {
        $viewer = User::factory()->create(['mobile' => '09222222222']);

        $this->actingAsSession($viewer)
            ->postJson("/api/products/{$this->product->id}/balance", ['amount' => 1])
            ->assertForbidden();
    }

    public function test_persons_can_be_created_by_balance_holders(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/persons', ['name' => 'مشتری الف', 'mobile' => '09333333333'])
            ->assertCreated()
            ->assertJsonPath('name', 'مشتری الف');

        $this->assertDatabaseHas('persons', ['name' => 'مشتری الف']);
    }

    public function test_persons_index_lists_product_balances(): void
    {
        PersonProduct::create([
            'person_id' => $this->person->id,
            'product_id' => $this->product->id,
            'quantity' => 42,
        ]);

        $this->actingAsSession($this->admin)
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonFragment(['quantity' => 42.0, 'status' => 'بدهکار']);
    }

    public function test_person_status_is_debtor_creditor_or_settled(): void
    {
        $debtor = Person::factory()->create(['name' => 'الف']);
        $creditor = Person::factory()->create(['name' => 'ب']);
        $settled = Person::factory()->create(['name' => 'پ']);
        $fresh = Person::factory()->create(['name' => 'ت']);

        PersonProduct::create(['person_id' => $debtor->id, 'product_id' => $this->product->id, 'quantity' => 10]);
        PersonProduct::create(['person_id' => $creditor->id, 'product_id' => $this->product->id, 'quantity' => -5]);
        PersonProduct::create(['person_id' => $settled->id, 'product_id' => $this->product->id, 'quantity' => 0]);

        $persons = collect($this->actingAsSession($this->admin)->getJson('/api/persons')->assertOk()->json());

        $this->assertSame('بدهکار', $persons->firstWhere('name', 'الف')['status']);
        $this->assertSame('بدهکار', $persons->firstWhere('name', 'الف')['products'][0]['status']);
        $this->assertSame('طلبکار', $persons->firstWhere('name', 'ب')['status']);
        $this->assertSame('تسویه', $persons->firstWhere('name', 'پ')['status']);
        $this->assertSame('تسویه', $persons->firstWhere('name', 'ت')['status']);
        $freshProducts = $persons->firstWhere('name', 'ت')['products'];
        $this->assertSame('طلا', $freshProducts[0]['name']);
        $this->assertEquals(0, $freshProducts[0]['quantity']);
        $this->assertSame('تسویه', $freshProducts[0]['status']);
    }

    public function test_history_includes_person_name_and_jalali_date(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", [
                'amount' => -25, 'person_id' => $this->person->id,
            ])
            ->assertCreated();

        $this->actingAsSession($this->admin)
            ->getJson('/api/history')
            ->assertOk()
            ->assertJsonStructure([['person' => ['name'], 'created_at_jalali']]);

        $this->assertStringContainsString(
            '/',
            BalanceChange::first()->created_at_jalali
        );
    }

    public function test_product_update_changes_fields(): void
    {
        $this->actingAsSession($this->admin)
            ->putJson("/api/products/{$this->product->id}", [
                'name' => 'طلای ۱۸', 'sku' => 'AU18', 'quantity' => 90, 'unit' => 'مثقال',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'طلای ۱۸');

        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'quantity' => 90]);
    }

    public function test_user_without_edit_permission_cannot_edit_product(): void
    {
        $viewer = User::factory()->create(['mobile' => '09222222222']);

        $this->actingAsSession($viewer)
            ->putJson("/api/products/{$this->product->id}", [
                'name' => 'x', 'quantity' => 1, 'unit' => 'عدد',
            ])
            ->assertForbidden();
    }

    public function test_weight_units_accept_decimal_amounts(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->product->id}/balance", ['amount' => 2.5])
            ->assertCreated()
            ->assertJsonPath('new_quantity', 102.5);

        $this->assertSame(2.5, BalanceChange::first()->change_amount);
    }

    public function test_weight_unit_accepts_decimal_quantity_on_create(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products', ['name' => 'طلای آب‌شده', 'quantity' => 12.345, 'unit' => 'گرم'])
            ->assertCreated()
            ->assertJsonPath('quantity', 12.345);
    }

    public function test_piece_unit_rejects_decimal_quantity(): void
    {
        $this->actingAsSession($this->admin)
            ->postJson('/api/products', ['name' => 'سکه', 'quantity' => 1.5, 'unit' => 'عدد'])
            ->assertStatus(422);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
