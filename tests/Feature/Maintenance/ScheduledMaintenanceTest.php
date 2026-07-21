<?php

namespace Tests\Feature\Maintenance;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledMaintenanceTest extends TestCase
{
    public function test_model_pruning_is_scheduled_daily(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list'));

        $output = Artisan::output();

        $this->assertStringContainsString('0 0 * * *', $output);
        $this->assertStringContainsString('model:prune', $output);
    }
}
