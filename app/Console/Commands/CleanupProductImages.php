<?php

namespace App\Console\Commands;

use App\Services\Products\ProductImageService;
use Illuminate\Console\Command;

class CleanupProductImages extends Command
{
    protected $signature = 'product-images:cleanup {--limit=100 : Maximum pending files to inspect}';

    protected $description = 'Delete unused product images recorded for retry';

    public function handle(ProductImageService $images): int
    {
        $completed = $images->cleanupPending((int) $this->option('limit'));
        $this->info("Completed {$completed} pending product image cleanup(s).");

        return self::SUCCESS;
    }
}
