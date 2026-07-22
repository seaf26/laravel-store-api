<?php

namespace Tests\Feature\Maintenance;

use App\Models\PendingFileDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cleanup_command_removes_a_pending_file_and_its_record(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/orphan.jpg', 'image');
        PendingFileDeletion::create(['disk' => 'public', 'path' => 'products/orphan.jpg']);

        $this->artisan('product-images:cleanup')->assertSuccessful();

        Storage::disk('public')->assertMissing('products/orphan.jpg');
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_cleanup_is_idempotent_when_the_file_is_already_absent(): void
    {
        Storage::fake('public');
        PendingFileDeletion::create(['disk' => 'public', 'path' => 'products/already-gone.jpg']);

        $this->artisan('product-images:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_product_image_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('product-images:cleanup')
            ->assertSuccessful();
    }
}
