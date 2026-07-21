<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_an_admin_can_create_a_product_with_an_image(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('shoe.jpg');

        $response = $this->actingAs($this->admin())->postJson('/api/products', [
            'title' => 'Running Shoe',
            'price' => 49.99,
            'description' => 'Lightweight running shoe.',
            'stock' => 25,
            'image' => $image,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Running Shoe')
            ->assertJsonPath('data.stock', 25)
            ->assertJsonPath('data.in_stock', true);

        $this->assertDatabaseHas('products', ['title' => 'Running Shoe']);
        $path = Product::first()->image_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_regular_user_cannot_create_a_product(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create())->postJson('/api/products', [
            'title' => 'Running Shoe',
            'price' => 49.99,
            'description' => 'Lightweight running shoe.',
            'stock' => 25,
            'image' => UploadedFile::fake()->image('shoe.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/products', [])->assertUnauthorized();
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_product_creation_is_validated(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->postJson('/api/products', [
            'title' => '',
            'price' => -5,
            'description' => '',
            'stock' => -1,
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'price', 'description', 'stock', 'image']);
    }

    public function test_updating_a_product_replaces_the_old_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $product = Product::factory()->create([
            'image_path' => UploadedFile::fake()->image('old.jpg')->store('products', 'public'),
        ]);
        $oldPath = $product->image_path;

        $response = $this->actingAs($admin)->putJson("/api/products/{$product->id}", [
            'title' => 'Updated title',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertOk()->assertJsonPath('data.title', 'Updated title');

        $newPath = $product->fresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_an_admin_can_delete_a_product_and_its_image(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create([
            'image_path' => UploadedFile::fake()->image('gone.jpg')->store('products', 'public'),
        ]);
        $path = $product->image_path;

        $this->actingAs($this->admin())->deleteJson("/api/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_regular_user_cannot_update_or_delete(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/api/products/{$product->id}", ['title' => 'x'])
            ->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/products/{$product->id}")
            ->assertForbidden();
    }

    public function test_any_authenticated_user_can_browse_and_view_products(): void
    {
        Product::factory()->count(3)->create();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(3, 'data');

        $product = Product::first();
        $this->actingAs($user)->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_viewing_a_missing_product_returns_404(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/products/999')
            ->assertNotFound();
    }
}
