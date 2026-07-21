<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idempotency_keys', 'request_hash')) {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->string('request_hash', 64)->nullable();
            });
        }

        DB::table('idempotency_keys')
            ->whereNull('request_hash')
            ->orderBy('id')
            ->chunkById(500, function (Collection $keys): void {
                $itemsByOrder = DB::table('order_items')
                    ->whereIn('order_id', $keys->pluck('order_id'))
                    ->get(['order_id', 'product_id', 'quantity'])
                    ->groupBy('order_id');

                foreach ($keys as $key) {
                    $quantities = [];

                    foreach ($itemsByOrder->get($key->order_id, collect()) as $item) {
                        $productId = (int) $item->product_id;
                        $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item->quantity;
                    }

                    ksort($quantities, SORT_NUMERIC);

                    $canonicalItems = [];
                    foreach ($quantities as $productId => $quantity) {
                        $canonicalItems[] = [
                            'product_id' => (int) $productId,
                            'quantity' => $quantity,
                        ];
                    }

                    DB::table('idempotency_keys')
                        ->where('id', $key->id)
                        ->whereNull('request_hash')
                        ->update([
                            'request_hash' => hash(
                                'sha256',
                                json_encode($canonicalItems, JSON_THROW_ON_ERROR),
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('idempotency_keys', 'request_hash')) {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->dropColumn('request_hash');
            });
        }
    }
};
