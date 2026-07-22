<?php

declare(strict_types=1);

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\User;
use App\Notifications\NewProductNotification;
use App\Services\Notifications\NotificationDeduplicator;
use App\Services\Orders\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $mode, $barrier, $ready, $result, $userId, $subjectId] = $argv + array_fill(0, 7, null);

if (! is_string($mode) || ! is_string($barrier) || ! is_string($ready) || ! is_string($result)) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(64);
}

$writeResult = static function (array $payload) use ($result): void {
    file_put_contents($result, json_encode($payload, JSON_THROW_ON_ERROR));
};

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::purge();
    DB::reconnect();

    file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 15;

    while (! is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the release barrier.');
        }

        usleep(10_000);
    }

    $user = User::findOrFail((int) $userId);

    if ($mode === 'order') {
        try {
            $placed = $app->make(OrderService::class)->place(
                $user,
                [['product_id' => (int) $subjectId, 'quantity' => 1]],
                null,
            );
            $writeResult(['outcome' => 'created', 'order_id' => $placed->order->id]);
        } catch (InsufficientStockException) {
            $writeResult(['outcome' => 'insufficient_stock', 'order_id' => null]);
        }
    } elseif ($mode === 'notification') {
        $product = Product::findOrFail((int) $subjectId);
        $sent = $app->make(NotificationDeduplicator::class)->sendOnce(
            $user,
            new NewProductNotification($product),
            "new-product:{$product->id}",
        );
        $writeResult(['outcome' => $sent ? 'delivered' : 'duplicate', 'order_id' => null]);
    } else {
        throw new RuntimeException("Unknown worker mode: {$mode}");
    }
} catch (Throwable $exception) {
    $writeResult(['outcome' => 'error', 'order_id' => null, 'message' => $exception->getMessage()]);
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(2);
}
