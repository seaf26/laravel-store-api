<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_searched_by_title_or_description(): void
    {
        Product::factory()->create(['title' => 'Blue Running Shoe', 'description' => 'fast']);
        Product::factory()->create(['title' => 'Red Hat', 'description' => 'a running accessory']);
        Product::factory()->create(['title' => 'Green Mug', 'description' => 'ceramic']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/products?search=running')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_products_can_be_filtered_by_price_range_and_stock(): void
    {
        Product::factory()->create(['title' => 'cheap', 'price' => 5, 'stock' => 3]);
        Product::factory()->create(['title' => 'mid', 'price' => 50, 'stock' => 0]);
        Product::factory()->create(['title' => 'pricey', 'price' => 500, 'stock' => 3]);

        $user = User::factory()->create();

        $inRange = $this->actingAs($user)->getJson('/api/products?min_price=10&max_price=100')->json('data');
        $this->assertCount(1, $inRange);
        $this->assertSame('mid', $inRange[0]['title']);

        $inStock = $this->actingAs($user)->getJson('/api/products?in_stock=1')->json('data');
        $this->assertCount(2, $inStock);
    }

    public function test_products_can_be_sorted_by_a_whitelisted_field(): void
    {
        Product::factory()->create(['title' => 'B', 'price' => 30]);
        Product::factory()->create(['title' => 'A', 'price' => 10]);
        Product::factory()->create(['title' => 'C', 'price' => 20]);

        $prices = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/api/products?sort=price&direction=asc')
                ->json('data')
        )->pluck('price')->all();

        $this->assertSame(['10.00', '20.00', '30.00'], $prices);
    }

    public function test_an_invalid_sort_field_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/products?sort=stock;drop')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_pagination_is_bounded(): void
    {
        Product::factory()->count(5)->create();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/products?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_invalid_product_listing_values_are_rejected_instead_of_cast_or_clamped(): void
    {
        $user = User::factory()->create();
        $cases = [
            ['search[]=shoe', 'search'],
            ['min_price=-1', 'min_price'],
            ['max_price=not-a-number', 'max_price'],
            ['in_stock=maybe', 'in_stock'],
            ['direction=sideways', 'direction'],
            ['per_page=0', 'per_page'],
            ['per_page=101', 'per_page'],
            ['page=0', 'page'],
        ];

        foreach ($cases as [$query, $field]) {
            $this->actingAs($user)
                ->getJson("/api/products?{$query}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_an_inverted_product_price_range_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/products?min_price=100&max_price=50')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_price');
    }
}
