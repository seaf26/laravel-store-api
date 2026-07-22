<?php

namespace Tests\Feature\Concurrency;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionDatabaseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_buyers_cannot_oversell_one_unit(): void
    {
        $this->requireProductionConcurrencyEnvironment();
        $product = Product::factory()->create(['stock' => 1, 'price' => 10]);
        $users = User::factory()->count(2)->create();

        $results = $this->race('order', [$users[0]->id, $users[1]->id], $product->id);

        $this->assertSame(['created', 'insufficient_stock'], collect($results)->pluck('outcome')->sort()->values()->all());
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_two_independent_workers_create_one_durable_notification(): void
    {
        $this->requireProductionConcurrencyEnvironment();
        $product = Product::factory()->create();
        $user = User::factory()->create();

        $results = $this->race('notification', [$user->id, $user->id], $product->id);

        $this->assertSame(['delivered', 'duplicate'], collect($results)->pluck('outcome')->sort()->values()->all());
        $this->assertDatabaseCount('notifications', 1);
    }

    /**
     * @param  array{0: int, 1: int}  $userIds
     * @return array<int, array{outcome: string, order_id: ?int, message?: string}>
     */
    private function race(string $mode, array $userIds, int $subjectId): array
    {
        $directory = sys_get_temp_dir().'/store-api-concurrency-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $barrier = $directory.'/release';
        $processes = [];

        try {
            foreach ($userIds as $index => $userId) {
                $ready = $directory."/ready-{$index}";
                $result = $directory."/result-{$index}.json";
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_worker.php'),
                    $mode,
                    $barrier,
                    $ready,
                    $result,
                    (string) $userId,
                    (string) $subjectId,
                ], base_path(), $this->childEnvironment(), null, 25);
                $process->start();
                $processes[] = compact('process', 'ready', 'result');
            }

            $deadline = microtime(true) + 12;
            while (collect($processes)->contains(fn (array $worker) => ! is_file($worker['ready']))) {
                foreach ($processes as $worker) {
                    if (! $worker['process']->isRunning() && ! is_file($worker['ready'])) {
                        $this->fail('Concurrency worker exited before ready: '.$worker['process']->getErrorOutput());
                    }
                }

                if (microtime(true) >= $deadline) {
                    $this->fail('Timed out waiting for concurrency workers to become ready.');
                }

                usleep(10_000);
            }

            touch($barrier);
            $results = [];

            foreach ($processes as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $this->assertFileExists($worker['result']);
                $payload = json_decode((string) file_get_contents($worker['result']), true, flags: JSON_THROW_ON_ERROR);
                $this->assertContains($payload['outcome'] ?? null, ['created', 'insufficient_stock', 'delivered', 'duplicate']);
                $results[] = $payload;
            }

            return $results;
        } finally {
            foreach ($processes as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop(1);
                }
            }

            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, string> */
    private function childEnvironment(): array
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}");

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SMS_SENDER' => 'log',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) ($database['host'] ?? ''),
            'DB_PORT' => (string) ($database['port'] ?? ''),
            'DB_DATABASE' => (string) ($database['database'] ?? ''),
            'DB_USERNAME' => (string) ($database['username'] ?? ''),
            'DB_PASSWORD' => (string) ($database['password'] ?? ''),
        ];
    }

    private function requireProductionConcurrencyEnvironment(): void
    {
        if (! in_array(config('database.default'), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires MySQL or PostgreSQL row-lock semantics.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Requires proc_open for independent worker processes.');
        }
    }
}
