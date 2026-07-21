<?php

namespace Tests\Feature\Order;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaying_the_same_key_returns_the_original_order_without_a_duplicate(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 2]]];

        $first = $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'abc-123'])
            ->postJson('/api/orders', $payload)
            ->assertCreated();

        $second = $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'abc-123'])
            ->postJson('/api/orders', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        // Same order returned, created once, stock decremented once.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_a_different_key_creates_a_new_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->actingAs($user)->withHeaders(['Idempotency-Key' => 'key-1'])
            ->postJson('/api/orders', $payload)->assertCreated();
        $this->actingAs($user)->withHeaders(['Idempotency-Key' => 'key-2'])
            ->postJson('/api/orders', $payload)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_the_same_key_from_different_users_is_independent(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->actingAs(User::factory()->create())->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson('/api/orders', $payload)->assertCreated();
        $this->actingAs(User::factory()->create())->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson('/api/orders', $payload)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
    }
}
