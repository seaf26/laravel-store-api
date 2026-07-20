<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockSubscriptionController extends Controller
{
    /**
     * Subscribe the authenticated user to a product's back-in-stock alert.
     *
     * Only allowed while the product is out of stock. The request is
     * idempotent: repeating it returns the existing subscription rather than
     * creating a duplicate (the unique constraint is the final backstop).
     */
    public function __invoke(Request $request, Product $product): JsonResponse
    {
        if (! $product->isOutOfStock()) {
            return response()->json([
                'message' => 'Product is in stock.',
            ], 422);
        }

        $subscription = $product->stockSubscriptions()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'You will be notified when this product is back in stock.',
            'data' => [
                'product_id' => $product->id,
                'subscribed_at' => $subscription->created_at->toIso8601String(),
            ],
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }
}
