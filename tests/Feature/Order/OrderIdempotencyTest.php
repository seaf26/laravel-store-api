<?php

namespace Tests\Feature\Order;

use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_reordered_equivalent_items_replay_the_original_order(): void
    {
        $user = User::factory()->create();
        $productA = Product::factory()->create(['stock' => 10]);
        $productB = Product::factory()->create(['stock' => 10]);

        $first = $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'reordered'])
            ->postJson('/api/orders', ['items' => [
                ['product_id' => $productA->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'quantity' => 3],
            ]])
            ->assertCreated();

        $second = $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'reordered'])
            ->postJson('/api/orders', ['items' => [
                ['product_id' => $productB->id, 'quantity' => 3],
                ['product_id' => $productA->id, 'quantity' => 2],
            ]])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $productA->fresh()->stock);
        $this->assertSame(7, $productB->fresh()->stock);
    }

    public function test_reusing_a_key_for_different_items_returns_conflict_without_mutation(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'conflict'])
            ->postJson('/api/orders', ['items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ]])
            ->assertCreated();

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'conflict'])
            ->postJson('/api/orders', ['items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ]])
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The idempotency key was already used with a different request.',
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_an_idempotency_key_longer_than_64_characters_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => str_repeat('x', 65)])
            ->postJson('/api/orders', ['items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key')
            ->assertJsonPath(
                'errors.Idempotency-Key.0',
                'The idempotency key must not be greater than 64 characters.',
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_the_migration_backfills_a_legacy_key_from_recorded_order_items(): void
    {
        $user = User::factory()->create();
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => 0,
        ]);
        $order->items()->createMany([
            ['product_id' => $productB->id, 'quantity' => 3, 'unit_price' => $productB->price],
            ['product_id' => $productA->id, 'quantity' => 2, 'unit_price' => $productA->price],
        ]);
        $key = IdempotencyKey::create([
            'user_id' => $user->id,
            'key' => 'legacy-key',
            'order_id' => $order->id,
        ]);

        DB::table('idempotency_keys')->where('id', $key->id)->update(['request_hash' => null]);

        $migration = require database_path('migrations/2026_07_22_000000_add_request_hash_to_idempotency_keys_table.php');
        $migration->up();

        $canonicalJson = json_encode([
            ['product_id' => min($productA->id, $productB->id), 'quantity' => 2],
            ['product_id' => max($productA->id, $productB->id), 'quantity' => 3],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(
            hash('sha256', $canonicalJson),
            DB::table('idempotency_keys')->where('id', $key->id)->value('request_hash'),
        );
    }
}
