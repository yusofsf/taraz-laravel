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
