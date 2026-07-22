<?php

namespace Tests\Feature\Maintenance;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledMaintenanceTest extends TestCase
{
    public function test_model_pruning_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $modelPrune = $events->first(
            fn ($event): bool => str_contains($event->command, 'model:prune'),
        );
        $imageCleanup = $events->first(
            fn ($event): bool => str_contains($event->command, 'product-images:cleanup --limit=500'),
        );

        $this->assertNotNull($modelPrune);
        $this->assertSame('0 0 * * *', $modelPrune->expression);
        $this->assertNotNull($imageCleanup);
        $this->assertSame('*/5 * * * *', $imageCleanup->expression);
    }
}
