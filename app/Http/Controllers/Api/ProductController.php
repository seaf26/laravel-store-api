<?php

namespace App\Http\Controllers\Api;

use App\Events\ProductCreated;
use App\Http\Concerns\SortsQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use SortsQueries;

    /**
     * Paginated catalogue listing with filtering and sorting. Open to any
     * authenticated user.
     *
     * Filters: search, min_price, max_price, in_stock.
     * Sort: price | title | created_at (direction asc|desc).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        [$sort, $direction] = $this->resolveSort(
            $request,
            allowed: ['price', 'title', 'created_at'],
            default: 'created_at',
        );

        $products = Product::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->float('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->float('max_price')))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock', '>', 0))
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

        $data = $request->safe()->except('image');
        $data['image_path'] = $request->file('image')->store('products', 'public');

        $product = Product::create($data);

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

        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            // Replace the old file so orphaned images do not accumulate.
            $this->deleteImage($product);
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);

        return new ProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->deleteImage($product);
        $product->delete();

        return response()->json(status: 204);
    }

    private function deleteImage(Product $product): void
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
    }
}
