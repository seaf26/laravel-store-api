<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * List orders. A regular user sees only their own; an admin sees all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->with('items.product')
            // Non-admins are scoped to their own orders at the query level, so
            // another user's orders can never appear in the list.
            ->when(! $request->user()->is_admin, function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->latest()
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }

    /**
     * Show a single order, if the caller owns it or is an admin.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('items.product'));
    }

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

    private function perPage(Request $request): int
    {
        return (int) min(max((int) $request->integer('per_page', 15), 1), 100);
    }
}
