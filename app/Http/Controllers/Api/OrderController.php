<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Place a new order for the authenticated user.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->place(
            $request->user(),
            $request->validated('items'),
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }
}
