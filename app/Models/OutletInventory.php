<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $reorder_level
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $last_counted_at
 * @property-read ProductVariant $variant
 * @property-read Product $product
 */
class OutletInventory extends Model
{
    use BelongsToOutlet;

    protected $fillable = ['outlet_id', 'warehouse_id', 'product_id', 'product_variant_id', 'quantity', 'reorder_level', 'version', 'last_counted_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'version' => 'integer',
            'last_counted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('quantity', '<=', 'reorder_level');
    }
}

