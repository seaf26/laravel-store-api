<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProductObserver::class)]
#[Fillable(['title', 'price', 'description', 'stock', 'image_path'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
        ];
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Users waiting to hear that this product is back in stock.
     *
     * @return HasMany<StockSubscription, $this>
     */
    public function stockSubscriptions(): HasMany
    {
        return $this->hasMany(StockSubscription::class);
    }
}
