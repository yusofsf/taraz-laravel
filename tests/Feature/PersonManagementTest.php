<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PersonManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $editor;

    private User $deleter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->editor = User::factory()->create(['mobile' => '09222222222', 'can_edit_persons' => true]);
        $this->deleter = User::factory()->create(['mobile' => '09333333333', 'can_delete_persons' => true]);
    }

    public function test_requires_edit_permission_to_create(): void
    {
        $this->actingAsSession($this->deleter)
            ->postJson('/api/persons', ['name' => 'علی'])
            ->assertStatus(403);
    }

    public function test_requires_edit_permission_to_update(): void
    {
        $person = Person::create(['name' => 'علی']);

        $this->actingAsSession($this->deleter)
            ->putJson("/api/persons/{$person->id}", ['name' => 'علی رضایی'])
            ->assertStatus(403);
    }

    public function test_requires_delete_permission_to_destroy(): void
    {
        $person = Person::create(['name' => 'علی']);

        $this->actingAsSession($this->editor)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertStatus(403);
    }

    public function test_editor_can_create_and_update_person(): void
    {
        $person = $this->actingAsSession($this->editor)
            ->postJson('/api/persons', ['name' => 'علی', 'mobile' => '09121234567'])
            ->assertStatus(201)
            ->json();

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person['id']}", ['name' => 'علی رضایی', 'mobile' => '09121234568', 'note' => 'مشتری قدیمی'])
            ->assertStatus(200)
            ->assertJson(['name' => 'علی رضایی', 'mobile' => '09121234568', 'note' => 'مشتری قدیمی']);
    }

    public function test_deleter_can_delete_person_without_history(): void
    {
        $person = Person::create(['name' => 'علی']);
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        PersonProduct::create(['person_id' => $person->id, 'product_id' => $product->id, 'quantity' => 5]);

        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('persons', ['id' => $person->id]);
        $this->assertDatabaseMissing('person_product', ['person_id' => $person->id]);
    }

    public function test_cannot_delete_person_with_history(): void
    {
        $person = Person::create(['name' => 'علی']);
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        BalanceChange::create([
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'person_id' => $person->id,
            'change_amount' => 10,
            'previous_quantity' => 0,
            'new_quantity' => 10,
        ]);

        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('persons', ['id' => $person->id]);
    }

    public function test_cannot_delete_person_who_is_only_a_havale_party(): void
    {
        $person = Person::create(['name' => 'علی']);
        $counterparty = Person::create(['name' => 'رضا']);
        $product = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        BalanceChange::create([
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'type' => 'trade',
            'from_person_id' => $person->id,
            'to_person_id' => $counterparty->id,
            'change_amount' => 2,
            'total_price' => 1000,
            'previous_quantity' => 0,
            'new_quantity' => 2,
        ]);

        // شخص در هیچ رکوردی به‌عنوان person_id نیست، اما طرفِ حواله است و حذف نمی‌شود
        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('persons', ['id' => $person->id]);
    }

    public function test_update_edits_per_product_balances(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        $paper = Product::create(['name' => 'کاغذ', 'quantity' => 0, 'unit' => 'عدد']);
        PersonProduct::create(['person_id' => $person->id, 'product_id' => $gold->id, 'quantity' => 5]);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 3.5],
                    ['id' => $paper->id, 'quantity' => -1200],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('person_product', ['person_id' => $person->id, 'product_id' => $gold->id, 'quantity' => 3.5]);
        $this->assertDatabaseHas('person_product', ['person_id' => $person->id, 'product_id' => $paper->id, 'quantity' => -1200]);
    }

    public function test_update_applies_balance_delta_to_overall_product_balance(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 100, 'unit' => 'گرم']);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 8],
                ],
            ])
            ->assertStatus(200);

        // دلتای +۸ روی تراز کلی کالا هم اعمال شده است
        $this->assertSame(108.0, (float) $gold->fresh()->quantity);

        $change = BalanceChange::where('product_id', $gold->id)->where('note', 'ویرایش دستی تراز شخص')->first();
        $this->assertNotNull($change);
        $this->assertSame(8.0, (float) $change->change_amount);
        $this->assertSame($person->id, $change->person_id);
        $this->assertSame(100.0, (float) $change->previous_quantity);
        $this->assertSame(108.0, (float) $change->new_quantity);
    }

    public function test_update_applies_negative_delta_and_zero_out(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 50, 'unit' => 'گرم']);
        PersonProduct::create(['person_id' => $person->id, 'product_id' => $gold->id, 'quantity' => 5]);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => -3],
                ],
            ])
            ->assertStatus(200);

        // مقدار قبلی ۵ بوده؛ مقدار جدید -۳ یعنی دلتای -۸
        $this->assertSame(42.0, (float) $gold->fresh()->quantity);
        $this->assertDatabaseHas('person_product', ['person_id' => $person->id, 'product_id' => $gold->id, 'quantity' => -3]);

        // صفر کردن مقدار هم دلتا می‌سازد و تسویه در تاریخچه ثبت می‌شود
        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 0],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(45.0, (float) $gold->fresh()->quantity);
        $this->assertSame(2, BalanceChange::where('product_id', $gold->id)->where('note', 'ویرایش دستی تراز شخص')->count());
    }

    public function test_update_without_quantity_change_records_nothing(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 20, 'unit' => 'گرم']);
        PersonProduct::create(['person_id' => $person->id, 'product_id' => $gold->id, 'quantity' => 5]);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی رضایی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 5],
                ],
            ])
            ->assertStatus(200);

        // مقدار کالا تغییر نکرده؛ نه تراز کلی عوض می‌شود نه رکوردی ثبت می‌شود
        $this->assertSame(20.0, (float) $gold->fresh()->quantity);
        $this->assertSame(0, BalanceChange::where('product_id', $gold->id)->count());
    }

    public function test_update_rejects_duplicate_product_entries(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 10, 'unit' => 'گرم']);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 1],
                    ['id' => $gold->id, 'quantity' => 2],
                ],
            ])
            ->assertStatus(422);

        // دلتا دوبار اعمال نشده است
        $this->assertSame(10.0, (float) $gold->fresh()->quantity);
    }

    public function test_update_rejects_invalid_product_quantity(): void
    {
        $person = Person::create(['name' => 'علی']);
        $gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => $gold->id, 'quantity' => 'زیاد'],
                ],
            ])
            ->assertStatus(422);

        $this->actingAsSession($this->editor)
            ->putJson("/api/persons/{$person->id}", [
                'name' => 'علی',
                'products' => [
                    ['id' => 999999, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_search_filters_by_name_or_mobile(): void
    {
        Person::create(['name' => 'علی رضایی', 'mobile' => '09121110000']);
        Person::create(['name' => 'رضا محمدی', 'mobile' => '09122220000']);
        Person::create(['name' => 'مریم']);

        $byName = $this->actingAsSession($this->admin)->getJson('/api/persons?q=رضا')->json();
        $this->assertCount(2, $byName);

        $byMobile = $this->actingAsSession($this->admin)->getJson('/api/persons?q=09121110000')->json();
        $this->assertCount(1, $byMobile);
        $this->assertSame('علی رضایی', $byMobile[0]['name']);

        $all = $this->actingAsSession($this->admin)->getJson('/api/persons')->json();
        $this->assertCount(3, $all);
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
