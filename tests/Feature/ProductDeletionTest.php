<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $editor;

    private User $deleter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->editor = User::factory()->create(['mobile' => '09222222222', 'can_edit_products' => true]);
        $this->deleter = User::factory()->create(['mobile' => '09333333333', 'can_delete_products' => true]);
    }

    public function test_requires_delete_permission_to_destroy(): void
    {
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);

        $this->actingAsSession($this->editor)
            ->deleteJson("/api/products/{$product->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_deleter_can_delete_product_without_history(): void
    {
        $product = Product::create(['name' => 'طلا', 'quantity' => 5, 'unit' => 'گرم']);
        $person = Person::create(['name' => 'علی']);
        PersonProduct::create(['person_id' => $person->id, 'product_id' => $product->id, 'quantity' => 3]);

        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/products/{$product->id}")
            ->assertStatus(200)
            ->assertJson(['message' => 'حذف شد.']);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('person_product', ['product_id' => $product->id]);
    }

    public function test_cannot_delete_product_with_history(): void
    {
        $product = Product::create(['name' => 'طلا', 'quantity' => 10, 'unit' => 'گرم']);
        BalanceChange::create([
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'type' => BalanceChange::TYPE_ADJUST,
            'change_amount' => 10,
            'previous_quantity' => 0,
            'new_quantity' => 10,
            'note' => 'ثبت کالا با تراز اولیه',
        ]);

        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/products/{$product->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_money_products_cannot_be_deleted(): void
    {
        foreach (['کاغذ', 'ریال'] as $money) {
            $product = Product::create(['name' => $money, 'quantity' => 0, 'unit' => 'عدد']);

            $this->actingAsSession($this->admin)
                ->deleteJson("/api/products/{$product->id}")
                ->assertStatus(409);

            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
