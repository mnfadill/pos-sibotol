<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outlet-level override: availability toggle and custom price.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property bool $is_available
 * @property string|null $price_override
 * @property int|null $updated_by
 */
class ProductOutletSetting extends Model
{
    use BelongsToOutlet;

    protected $fillable = ['outlet_id', 'product_id', 'product_variant_id', 'is_available', 'price_override', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price_override' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
