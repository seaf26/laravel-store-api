<?php

namespace Tests\Feature\Order;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_place_an_order_and_stock_is_decremented(): void
    {
        $user = User::factory()->create();
        $a = Product::factory()->create(['stock' => 10, 'price' => 5.00]);
        $b = Product::factory()->create(['stock' => 4, 'price' => 2.50]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2],
                ['product_id' => $b->id, 'quantity' => 3],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', '17.50'); // 2*5.00 + 3*2.50

        $this->assertSame(8, $a->fresh()->stock);
        $this->assertSame(1, $b->fresh()->stock);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_unit_price_is_snapshotted_at_purchase_time(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10, 'price' => 5.00]);

        $this->actingAs($user)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        // Price changes afterwards must not affect the existing order.
        $product->update(['price' => 99.00]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'unit_price' => '5.00',
        ]);
    }

    public function test_an_order_fails_entirely_when_any_item_is_short(): void
    {
        $user = User::factory()->create();
        $a = Product::factory()->create(['stock' => 10]);
        $b = Product::factory()->create(['stock' => 1]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2],
                ['product_id' => $b->id, 'quantity' => 5], // only 1 available
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock.');
        // "items.1" is a literal key (with a dot), so read it directly rather
        // than through dot-path notation.
        $this->assertSame(
            ["Only 1 left for '{$b->title}'."],
            $response->json('errors')['items.1'],
        );

        // Nothing changed: no order, no items, and BOTH stocks intact.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(10, $a->fresh()->stock);
        $this->assertSame(1, $b->fresh()->stock);
    }

    public function test_ordering_all_remaining_stock_is_allowed(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 3]);

        $this->actingAs($user)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_order_input_is_validated(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        // Empty items
        $this->actingAs($user)->postJson('/api/orders', ['items' => []])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        // Unknown product, zero quantity
        $this->actingAs($user)->postJson('/api/orders', [
            'items' => [
                ['product_id' => 999999, 'quantity' => 0],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id', 'items.0.quantity']);

        // Duplicate product lines are rejected.
        $this->actingAs($user)->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_placing_an_order_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnauthorized();
    }

    public function test_two_buyers_racing_for_the_last_unit_never_oversell(): void
    {
        $productId = Product::factory()->create(['stock' => 1])->id;
        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        // Two sequential attempts for the last unit: exactly one succeeds.
        $first = $this->actingAs($buyerA)->postJson('/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);
        $second = $this->actingAs($buyerB)->postJson('/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);

        $statuses = [$first->status(), $second->status()];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);

        // Stock never went negative; exactly one order exists.
        $this->assertSame(0, Product::find($productId)->stock);
        $this->assertDatabaseCount('orders', 1);
    }
}
