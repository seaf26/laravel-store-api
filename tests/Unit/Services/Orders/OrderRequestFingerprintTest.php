<?php

namespace Tests\Unit\Services\Orders;

use App\Services\Orders\OrderRequestFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderRequestFingerprintTest extends TestCase
{
    #[Test]
    public function it_aggregates_quantities_and_numeric_sorts_product_ids(): void
    {
        $fingerprint = new OrderRequestFingerprint;

        $hash = $fingerprint->hash([
            ['product_id' => 10, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
            ['product_id' => 10, 'quantity' => 3],
        ]);

        $canonicalJson = json_encode([
            ['product_id' => 2, 'quantity' => 1],
            ['product_id' => 10, 'quantity' => 5],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(hash('sha256', $canonicalJson), $hash);
    }

    #[Test]
    public function equivalent_item_sets_have_the_same_hash_regardless_of_line_order(): void
    {
        $fingerprint = new OrderRequestFingerprint;

        $first = $fingerprint->hash([
            ['product_id' => 12, 'quantity' => 4],
            ['product_id' => 3, 'quantity' => 2],
        ]);
        $second = $fingerprint->hash([
            ['quantity' => 2, 'product_id' => 3],
            ['quantity' => 4, 'product_id' => 12],
        ]);

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    #[Test]
    public function changing_a_quantity_changes_the_hash(): void
    {
        $fingerprint = new OrderRequestFingerprint;

        $this->assertNotSame(
            $fingerprint->hash([['product_id' => 1, 'quantity' => 1]]),
            $fingerprint->hash([['product_id' => 1, 'quantity' => 2]]),
        );
    }
}
