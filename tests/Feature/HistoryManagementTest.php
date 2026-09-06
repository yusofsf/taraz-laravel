<?php

namespace Tests\Feature;

use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HistoryManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $editor;

    private User $deleter;

    private Product $gold;

    private Person $ali;

    private Person $reza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'mobile' => '09111111111']);
        $this->editor = User::factory()->create(['mobile' => '09222222222', 'can_edit_history' => true]);
        $this->deleter = User::factory()->create(['mobile' => '09333333333', 'can_delete_history' => true]);
        $this->gold = Product::create(['name' => 'طلا', 'quantity' => 0, 'unit' => 'گرم']);
        $this->ali = Person::create(['name' => 'علی']);
        $this->reza = Person::create(['name' => 'رضا']);
    }

    public function test_requires_edit_permission_to_update(): void
    {
        $change = $this->recordChange(10);

        $this->actingAsSession($this->deleter)
            ->putJson("/api/history/{$change->id}", ['amount' => 5])
            ->assertStatus(403);
    }

    public function test_requires_delete_permission_to_destroy(): void
    {
        $change = $this->recordChange(10);

        $this->actingAsSession($this->editor)
            ->deleteJson("/api/history/{$change->id}")
            ->assertStatus(403);
    }

    public function test_edit_updates_amount_and_product_balance(): void
    {
        $change = $this->recordChange(10);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$change->id}", ['amount' => 15, 'note' => 'اصلاح شد'])
            ->assertOk()
            ->assertJsonPath('change_amount', 15)
            ->assertJsonPath('new_quantity', 15)
            ->assertJsonPath('note', 'اصلاح شد');

        $this->assertDatabaseHas('products', ['id' => $this->gold->id, 'quantity' => 15]);
    }

    public function test_edit_moves_balance_between_persons(): void
    {
        $change = $this->recordChange(10, $this->ali->id);
        $second = $this->recordChange(5);

        $this->actingAsSession($this->editor)
            ->putJson("/api/history/{$change->id}", ['amount' => 7, 'person_id' => $this->reza->id])
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $this->gold->id, 'quantity' => 12]);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->value('quantity'));
        $this->assertSame(7.0, (float) PersonProduct::where('person_id', $this->reza->id)->value('quantity'));

        // The later record keeps a consistent running balance.
        $this->assertSame(12.0, (float) $second->refresh()->new_quantity);
    }

    public function test_delete_rolls_back_product_and_person_balance(): void
    {
        $change = $this->recordChange(10, $this->ali->id);
        $this->recordChange(5);

        $this->actingAsSession($this->deleter)
            ->deleteJson("/api/history/{$change->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('balance_changes', ['id' => $change->id]);
        $this->assertDatabaseHas('products', ['id' => $this->gold->id, 'quantity' => 5]);
        $this->assertSame(0.0, (float) PersonProduct::where('person_id', $this->ali->id)->value('quantity'));

        $remaining = BalanceChange::sole();
        $this->assertSame(0.0, (float) $remaining->previous_quantity);
        $this->assertSame(5.0, (float) $remaining->new_quantity);
    }

    public function test_delete_recomputes_chain_when_first_record_is_removed(): void
    {
        $first = $this->recordChange(10);
        $second = $this->recordChange(4);

        $this->actingAsSession($this->deleter)->deleteJson("/api/history/{$first->id}")->assertNoContent();

        $second->refresh();
        // With the first record gone, the chain restarts from the derived base.
        $this->assertSame(0.0, (float) $second->previous_quantity);
        $this->assertSame(4.0, (float) $second->new_quantity);
    }

    private function recordChange(float $amount, ?int $personId = null): BalanceChange
    {
        $payload = ['amount' => $amount];
        if ($personId !== null) {
            $payload['person_id'] = $personId;
        }

        $response = $this->actingAsSession($this->admin)
            ->postJson("/api/products/{$this->gold->id}/balance", $payload)
            ->assertCreated();

        return BalanceChange::findOrFail($response->json('id'));
    }

    private function actingAsSession(User $user): self
    {
        return $this->withSession(['user_id' => $user->id]);
    }
}
