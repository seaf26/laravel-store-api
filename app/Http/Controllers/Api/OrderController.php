<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Concerns\SortsQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrdersRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use SortsQueries;

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStatusService $statuses,
    ) {}

    /**
     * List orders. A regular user sees only their own; an admin sees all.
     *
     * Filters: status (and user_id for admins).
     * Sort: created_at | total (direction asc|desc).
     */
    public function index(IndexOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        [$sort, $direction] = $this->resolveSort(
            $request,
            allowed: ['created_at', 'total'],
            default: 'created_at',
        );

        $orders = Order::query()
            ->with('items.product')
            // Non-admins are scoped to their own orders at the query level, so
            // another user's orders can never appear in the list.
            ->when(! $request->user()->is_admin, function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            // Only admins may filter by an arbitrary user.
            ->when($request->user()->is_admin && isset($filters['user_id']),
                fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy($sort, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    /**
     * Show a single order, if the caller owns it or is an admin.
     *
     * A non-owner gets 404, not 403: an order ID is not something another
     * user should be able to confirm exists just by probing this endpoint.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        if ($request->user()->cannot('view', $order)) {
            abort(404);
        }

        return new OrderResource($order->load('items.product'));
    }

    /**
     * Place a new order for the authenticated user.
     *
     * An optional `Idempotency-Key` header makes retries safe: repeating the
     * same request returns the original order (200, `Idempotency-Replayed: true`)
     * instead of creating a duplicate.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (is_string($idempotencyKey) && strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['The idempotency key must not be greater than 64 characters.'],
            ]);
        }

        $result = $this->orders->place(
            $request->user(),
            $request->validated('items'),
            $idempotencyKey ?: null,
        );

        return (new OrderResource($result->order))
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201)
            ->withHeaders($result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /**
     * Update an order's status (admin only). Records history and notifies the
     * owner; resubmitting the current status is an idempotent no-op.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $history = $this->statuses->change(
            $order,
            OrderStatus::from($request->validated('status')),
            $request->user(),
        );

        if ($history === null) {
            return response()->json([
                'message' => 'Status unchanged.',
                'data' => new OrderResource($order->refresh()->load('items.product')),
            ]);
        }

        return response()->json([
            'message' => 'Order status updated.',
            'data' => new OrderResource($order->fresh()->load('items.product')),
        ]);
    }
}
