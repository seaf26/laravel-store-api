<?php

namespace App\Http\Controllers\Api;

use App\Events\ProductCreated;
use App\Http\Concerns\SortsQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Products\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use SortsQueries;

    public function __construct(private readonly ProductImageService $images) {}

    /**
     * Paginated catalogue listing with filtering and sorting. Open to any
     * authenticated user.
     *
     * Filters: search, min_price, max_price, in_stock.
     * Sort: price | title | created_at (direction asc|desc).
     */
    public function index(IndexProductsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);
        $filters = $request->validated();

        [$sort, $direction] = $this->resolveSort(
            $request,
            allowed: ['price', 'title', 'created_at'],
            default: 'created_at',
        );

        $products = Product::query()
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = '%'.Str::lower($filters['search']).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(title) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$term]);
                });
            })
            ->when(isset($filters['min_price']), fn ($q) => $q->where('price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn ($q) => $q->where('price', '<=', $filters['max_price']))
            ->when(($filters['in_stock'] ?? false) === true || ($filters['in_stock'] ?? null) === '1', fn ($q) => $q->where('stock', '>', 0))
            ->orderBy($sort, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->images->create(
            $request->safe()->except('image'),
            $request->file('image'),
        );

        // Fan-out to customers happens on a queued listener, so it never delays
        // this response.
        ProductCreated::dispatch($product);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product = $this->images->update(
            $product,
            $request->safe()->except('image'),
            $request->file('image'),
        );

        return new ProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->images->delete($product);

        return response()->json(status: 204);
    }
}
