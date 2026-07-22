<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductImageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;
use Throwable;

class ProductImageSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_creation_compensates_the_new_file(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->with('products', Mockery::type(UploadedFile::class))->andReturn('products/new.jpg');
        $disk->shouldReceive('exists')->once()->with('products/new.jpg')->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with('products/new.jpg')->andReturnTrue();
        $service = $this->serviceWith($disk);

        $thrown = null;

        try {
            $service->create(['title' => 'Missing required fields'], UploadedFile::fake()->image('new.jpg'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected product persistence to fail.');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_failed_replacement_preserves_the_old_image_and_compensates_the_new_file(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/old.jpg']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn('products/new.jpg');
        $disk->shouldReceive('exists')->once()->with('products/new.jpg')->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with('products/new.jpg')->andReturnTrue();
        $service = $this->serviceWith($disk);

        $thrown = null;

        try {
            $service->update($product, ['title' => null], UploadedFile::fake()->image('new.jpg'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected product persistence to fail.');
        $this->assertSame('products/old.jpg', $product->fresh()->image_path);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_failed_old_file_deletion_remains_as_durable_cleanup_work(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/old.jpg']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn('products/new.jpg');
        $disk->shouldReceive('exists')->twice()->with('products/old.jpg')->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with('products/old.jpg')->andReturnFalse();

        $updated = $this->serviceWith($disk)->update(
            $product,
            ['title' => 'Updated'],
            UploadedFile::fake()->image('new.jpg'),
        );

        $this->assertSame('products/new.jpg', $updated->image_path);
        $this->assertDatabaseHas('pending_file_deletions', [
            'disk' => 'public',
            'path' => 'products/old.jpg',
            'attempts' => 1,
        ]);
    }

    public function test_failed_product_deletion_does_not_remove_its_image_or_leave_cleanup_work(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/in-use.jpg']);
        $user = User::factory()->create();
        $order = $user->orders()->create(['status' => 'pending', 'total' => 10]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldNotReceive('delete');

        $thrown = null;

        try {
            $this->serviceWith($disk)->delete($product);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected the restricted product deletion to fail.');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'image_path' => 'products/in-use.jpg']);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    private function serviceWith(FilesystemAdapter $disk): ProductImageService
    {
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('public')->andReturn($disk);

        return new ProductImageService($manager);
    }
}
